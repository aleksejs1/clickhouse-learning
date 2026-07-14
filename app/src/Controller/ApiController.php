<?php

namespace App\Controller;

use App\Schema;
use App\Service\ClickHouse;
use App\Sampling;
use App\TimeFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api')]
class ApiController extends AbstractController
{
    private const LIST_FILTERS = ['event_type', 'vehicle_id', 'driver_id'];
    private const HISTOGRAM_BUCKETS = 20;

    public function __construct(private ClickHouse $clickHouse)
    {
    }

    #[Route('/events', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = min(200, max(1, $request->query->getInt('limit', 50)));
        $offset = max(0, $request->query->getInt('offset', 0));

        $time = TimeFilter::fromRequest($request);
        $where = $time->conditions();
        $params = $time->params();
        foreach (self::LIST_FILTERS as $field) {
            $value = (string) $request->query->get($field, '');
            if ('' !== $value) {
                $where[] = sprintf('%1$s = {%1$s:String}', $field);
                $params[$field] = $value;
            }
        }
        $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

        // toStartOfHour первым — это префикс сортировочного ключа таблицы:
        // ClickHouse читает в порядке ключа и останавливается на LIMIT,
        // а не сортирует все строки (порядок результата тот же)
        $items = $this->clickHouse->select(
            "SELECT * FROM events {$whereSql}
             ORDER BY toStartOfHour(event_time) DESC, event_time DESC
             LIMIT {limit:UInt32} OFFSET {offset:UInt32}",
            $params + ['limit' => $limit, 'offset' => $offset],
        );
        $total = $this->clickHouse->select("SELECT count() AS c FROM events {$whereSql}", $params)[0]['c'];

        return $this->json(['items' => $items, 'total' => (int) $total]);
    }

    #[Route('/events/{id}', methods: ['GET'])]
    public function one(string $id): JsonResponse
    {
        return $this->json($this->fetchEvent($id));
    }

    /**
     * Данные одного графика: распределение поля $field среди «похожих»
     * (similar_by = value) и «остальных». Событие здесь не нужно — страница
     * передаёт значение сама, иначе каждый из ~20 параллельных chart-запросов
     * заново искал бы событие полным сканом по UUID.
     * См. docs/API.md §3.
     */
    #[Route('/chart/{field}', methods: ['GET'])]
    public function chart(string $field, Request $request): JsonResponse
    {
        $similarBy = (string) $request->query->get('similar_by', '');
        if (!Schema::isDimension($similarBy)) {
            return $this->json(['error' => 'similar_by must be one of: '.implode(', ', Schema::DIMENSIONS)], 400);
        }
        if ($field === $similarBy || (!Schema::isDimension($field) && !Schema::isMetric($field))) {
            return $this->json(['error' => 'invalid field'], 400);
        }
        $similarValue = $request->query->get('value');
        if (null === $similarValue) {
            return $this->json(['error' => 'value query parameter is required'], 400);
        }

        // Период сужает обе группы и позволяет отсекать гранулы по первичному
        // индексу (event_time — первый в ORDER BY таблицы); сэмплирование
        // читает долю строк по ключу SAMPLE BY, счётчики домножаются обратно
        $time = TimeFilter::fromRequest($request);
        $sampling = Sampling::fromRequest($request);
        $fromTail = trim($sampling->sql().' '.($time->conditions() ? 'WHERE '.implode(' AND ', $time->conditions()) : ''));
        $timeParams = $time->params();

        [$labels, $similar, $other] = Schema::isDimension($field)
            ? $this->dimensionCounts($field, $similarBy, $similarValue, $fromTail, $timeParams)
            : $this->metricHistogram($field, $similarBy, $similarValue, $fromTail, $timeParams);

        [$similarTotal, $otherTotal] = $this->groupTotals($similarBy, $similarValue, $fromTail, $timeParams);
        if ($sampling->isActive()) {
            $scale = static fn (int $c): int => (int) round($c * $sampling->scale());
            [$similar, $other] = [array_map($scale, $similar), array_map($scale, $other)];
            [$similarTotal, $otherTotal] = [$scale($similarTotal), $scale($otherTotal)];
        }
        $similarPct = $this->toPercent($similar, $similarTotal);
        $otherPct = $this->toPercent($other, $otherTotal);

        return $this->json([
            'field' => $field,
            'kind' => Schema::isDimension($field) ? 'dimension' : 'metric',
            'similar_by' => $similarBy,
            'similar_value' => $similarValue,
            'sample' => $sampling->isActive() ? (float) $sampling->rate : 1,
            'similar_total' => $similarTotal,
            'other_total' => $otherTotal,
            'labels' => $labels,
            'similar_pct' => $similarPct,
            'other_pct' => $otherPct,
            'divergence' => $this->divergence($similarPct, $otherPct, $similarTotal, $otherTotal),
            'queries' => $this->clickHouse->queryLog(),
        ]);
    }

    /**
     * Гео-распределение групп для карты: события агрегируются в ячейки
     * сетки ~1×1 км (округление координат до 2 знаков). См. docs/API.md §4.
     */
    #[Route('/map', methods: ['GET'])]
    public function map(Request $request): JsonResponse
    {
        $similarBy = (string) $request->query->get('similar_by', '');
        if (!Schema::isDimension($similarBy)) {
            return $this->json(['error' => 'similar_by must be one of: '.implode(', ', Schema::DIMENSIONS)], 400);
        }
        $similarValue = $request->query->get('value');
        if (null === $similarValue) {
            return $this->json(['error' => 'value query parameter is required'], 400);
        }

        $time = TimeFilter::fromRequest($request);
        $sampling = Sampling::fromRequest($request);
        $fromTail = trim($sampling->sql().' '.($time->conditions() ? 'WHERE '.implode(' AND ', $time->conditions()) : ''));
        $timeParams = $time->params();

        $cells = $this->clickHouse->select(
            "SELECT round(lat, 2) AS lat, round(lon, 2) AS lon,
                    countIf({$similarBy} = {val:String}) AS similar,
                    countIf({$similarBy} != {val:String}) AS other
             FROM events {$fromTail}
             GROUP BY lat, lon
             ORDER BY similar + other DESC
             LIMIT 4000",
            $timeParams + ['val' => $similarValue],
        );
        [$similarTotal, $otherTotal] = $this->groupTotals($similarBy, $similarValue, $fromTail, $timeParams);
        $scale = $sampling->scale();

        return $this->json([
            'similar_by' => $similarBy,
            'similar_value' => $similarValue,
            'sample' => $sampling->isActive() ? (float) $sampling->rate : 1,
            'similar_total' => (int) round($similarTotal * $scale),
            'other_total' => (int) round($otherTotal * $scale),
            'cells' => array_map(static fn (array $c): array => [
                'lat' => (float) $c['lat'],
                'lon' => (float) $c['lon'],
                'similar' => (int) round((int) $c['similar'] * $scale),
                'other' => (int) round((int) $c['other'] * $scale),
            ], $cells),
            'queries' => $this->clickHouse->queryLog(),
        ]);
    }

    /**
     * Размеры групп по всей выборке, а не по попавшим на график корзинам:
     * у dimension-графиков LIMIT 20, и проценты должны быть долей от группы.
     *
     * @return array{int, int}
     */
    private function groupTotals(string $similarBy, string $similarValue, string $fromTail, array $timeParams): array
    {
        $totals = $this->clickHouse->select(
            "SELECT countIf({$similarBy} = {val:String}) AS similar,
                    countIf({$similarBy} != {val:String}) AS other
             FROM events {$fromTail}",
            $timeParams + ['val' => $similarValue],
        )[0];

        return [(int) $totals['similar'], (int) $totals['other']];
    }

    /** @return array<string, mixed> */
    private function fetchEvent(string $id): array
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException('invalid event id');
        }

        $rows = $this->clickHouse->select(
            'SELECT * FROM events WHERE event_id = {id:UUID} LIMIT 1',
            ['id' => $id],
        );
        if (!$rows) {
            throw $this->createNotFoundException('event not found');
        }

        return $rows[0];
    }

    /** @return array{list<string>, list<int>, list<int>} */
    private function dimensionCounts(string $field, string $similarBy, string $similarValue, string $fromTail, array $timeParams): array
    {
        // $field и $similarBy уже проверены по белому списку Schema
        $rows = $this->clickHouse->select(
            "SELECT {$field} AS label,
                    countIf({$similarBy} = {val:String}) AS similar,
                    countIf({$similarBy} != {val:String}) AS other
             FROM events {$fromTail}
             GROUP BY label
             ORDER BY similar + other DESC
             LIMIT 20",
            $timeParams + ['val' => $similarValue],
        );

        return [
            array_map(strval(...), array_column($rows, 'label')),
            array_map(intval(...), array_column($rows, 'similar')),
            array_map(intval(...), array_column($rows, 'other')),
        ];
    }

    /** @return array{list<string>, list<int>, list<int>} */
    private function metricHistogram(string $field, string $similarBy, string $similarValue, string $fromTail, array $timeParams): array
    {
        $n = self::HISTOGRAM_BUCKETS;
        $rows = $this->clickHouse->select(
            "WITH (SELECT min({$field}) FROM events {$fromTail}) AS mn,
                  (SELECT max({$field}) FROM events {$fromTail}) AS mx
             SELECT widthBucket({$field}, mn, mx + 0.001, {$n}) AS bucket,
                    any(mn) AS mn_, any(mx) AS mx_,
                    countIf({$similarBy} = {val:String}) AS similar,
                    countIf({$similarBy} != {val:String}) AS other
             FROM events {$fromTail}
             GROUP BY bucket
             ORDER BY bucket",
            $timeParams + ['val' => $similarValue],
        );

        $min = $rows ? (float) $rows[0]['mn_'] : 0.0;
        $max = $rows ? (float) $rows[0]['mx_'] : 0.0;
        $width = ($max + 0.001 - $min) / $n;

        $labels = $similar = $other = [];
        for ($i = 1; $i <= $n; ++$i) {
            $labels[] = sprintf('%.1f–%.1f', $min + ($i - 1) * $width, $min + $i * $width);
            $similar[$i] = 0;
            $other[$i] = 0;
        }
        foreach ($rows as $row) {
            $bucket = (int) $row['bucket'];
            if ($bucket >= 1 && $bucket <= $n) {
                $similar[$bucket] = (int) $row['similar'];
                $other[$bucket] = (int) $row['other'];
            }
        }

        return [$labels, array_values($similar), array_values($other)];
    }

    /**
     * Насколько распределение «похожих» отличается от «остальных»: расстояние
     * полной вариации, 0 (совпадают) … 100 (не пересекаются). По нему страница
     * события сортирует графики — самые аномальные поля первыми.
     *
     * @param list<float> $similarPct
     * @param list<float> $otherPct
     */
    private function divergence(array $similarPct, array $otherPct, int $similarTotal, int $otherTotal): float
    {
        if (0 === $similarTotal || 0 === $otherTotal) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($similarPct as $i => $pct) {
            $sum += abs($pct - $otherPct[$i]);
        }

        return round($sum / 2, 1);
    }

    /** @return list<float> */
    private function toPercent(array $counts, int $total): array
    {
        return array_map(
            static fn (int $c): float => round($c / max(1, $total) * 100, 2),
            $counts,
        );
    }
}
