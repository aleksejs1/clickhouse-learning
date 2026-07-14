<?php

namespace App\Controller;

use App\Schema;
use App\Service\ClickHouse;
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

        $where = [];
        $params = [];
        foreach (self::LIST_FILTERS as $field) {
            $value = (string) $request->query->get($field, '');
            if ('' !== $value) {
                $where[] = sprintf('%1$s = {%1$s:String}', $field);
                $params[$field] = $value;
            }
        }
        $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

        $items = $this->clickHouse->select(
            "SELECT * FROM events {$whereSql}
             ORDER BY event_time DESC
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
     * (то же значение измерения similar_by, что у события $id) и «остальных».
     * См. docs/API.md §3.
     */
    #[Route('/events/{id}/chart/{field}', methods: ['GET'])]
    public function chart(string $id, string $field, Request $request): JsonResponse
    {
        $similarBy = (string) $request->query->get('similar_by', '');
        if (!Schema::isDimension($similarBy)) {
            return $this->json(['error' => 'similar_by must be one of: '.implode(', ', Schema::DIMENSIONS)], 400);
        }
        if ($field === $similarBy || (!Schema::isDimension($field) && !Schema::isMetric($field))) {
            return $this->json(['error' => 'invalid field'], 400);
        }

        $event = $this->fetchEvent($id);
        $similarValue = (string) $event[$similarBy];

        [$labels, $similar, $other] = Schema::isDimension($field)
            ? $this->dimensionCounts($field, $similarBy, $similarValue)
            : $this->metricHistogram($field, $similarBy, $similarValue);

        // Итоги по всей таблице, а не по попавшим на график корзинам: у
        // dimension-графиков LIMIT 20, и проценты должны быть долей от группы
        $totals = $this->clickHouse->select(
            "SELECT countIf({$similarBy} = {val:String}) AS similar,
                    countIf({$similarBy} != {val:String}) AS other
             FROM events",
            ['val' => $similarValue],
        )[0];
        $similarTotal = (int) $totals['similar'];
        $otherTotal = (int) $totals['other'];

        return $this->json([
            'field' => $field,
            'kind' => Schema::isDimension($field) ? 'dimension' : 'metric',
            'similar_by' => $similarBy,
            'similar_value' => $similarValue,
            'similar_total' => $similarTotal,
            'other_total' => $otherTotal,
            'labels' => $labels,
            'similar_pct' => $this->toPercent($similar, $similarTotal),
            'other_pct' => $this->toPercent($other, $otherTotal),
        ]);
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
    private function dimensionCounts(string $field, string $similarBy, string $similarValue): array
    {
        // $field и $similarBy уже проверены по белому списку Schema
        $rows = $this->clickHouse->select(
            "SELECT {$field} AS label,
                    countIf({$similarBy} = {val:String}) AS similar,
                    countIf({$similarBy} != {val:String}) AS other
             FROM events
             GROUP BY label
             ORDER BY similar + other DESC
             LIMIT 20",
            ['val' => $similarValue],
        );

        return [
            array_map(strval(...), array_column($rows, 'label')),
            array_map(intval(...), array_column($rows, 'similar')),
            array_map(intval(...), array_column($rows, 'other')),
        ];
    }

    /** @return array{list<string>, list<int>, list<int>} */
    private function metricHistogram(string $field, string $similarBy, string $similarValue): array
    {
        $n = self::HISTOGRAM_BUCKETS;
        $rows = $this->clickHouse->select(
            "WITH (SELECT min({$field}) FROM events) AS mn,
                  (SELECT max({$field}) FROM events) AS mx
             SELECT widthBucket({$field}, mn, mx + 0.001, {$n}) AS bucket,
                    any(mn) AS mn_, any(mx) AS mx_,
                    countIf({$similarBy} = {val:String}) AS similar,
                    countIf({$similarBy} != {val:String}) AS other
             FROM events
             GROUP BY bucket
             ORDER BY bucket",
            ['val' => $similarValue],
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

    /** @return list<float> */
    private function toPercent(array $counts, int $total): array
    {
        return array_map(
            static fn (int $c): float => round($c / max(1, $total) * 100, 2),
            $counts,
        );
    }
}
