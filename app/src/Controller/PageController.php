<?php

namespace App\Controller;

use App\Sampling;
use App\Schema;
use App\Service\ClickHouse;
use App\TimeFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class PageController extends AbstractController
{
    private const PAGE_SIZE = 50;
    private const DEFAULT_SIMILAR_BY = 'vehicle_id';
    private const ANOMALY_MIN_GROUP = 1000; // мелкие группы шумят
    private const ANOMALY_MIN_SIGMA = 0.1;

    public function __construct(private ClickHouse $clickHouse)
    {
    }

    #[Route('/', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(0, $request->query->getInt('page', 0));
        $eventType = (string) $request->query->get('event_type', '');
        $time = TimeFilter::fromRequest($request);

        $where = $time->conditions();
        $params = $time->params();
        if ('' !== $eventType) {
            $where[] = 'event_type = {event_type:String}';
            $params['event_type'] = $eventType;
        }
        $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

        // toStartOfHour первым — префикс ключа таблицы, чтение в порядке индекса
        $events = $this->clickHouse->select(
            "SELECT * FROM events {$whereSql}
             ORDER BY toStartOfHour(event_time) DESC, event_time DESC
             LIMIT {limit:UInt32} OFFSET {offset:UInt32}",
            $params + ['limit' => self::PAGE_SIZE, 'offset' => $page * self::PAGE_SIZE],
        );
        $total = (int) $this->clickHouse->select("SELECT count() AS c FROM events {$whereSql}", $params)[0]['c'];

        // Таймлайн читает почасовые агрегаты (MV events_by_hour, ~тысячи строк),
        // а не сырые события; sum() обязателен — SummingMergeTree сливает фоном
        $timeline = $this->clickHouse->select(
            'SELECT toString(hour) AS hour, sum(events) AS c
             FROM events_by_hour '.('' !== $eventType ? 'WHERE event_type = {event_type:String}' : '').'
             GROUP BY hour
             ORDER BY hour',
            '' !== $eventType ? ['event_type' => $eventType] : [],
        );
        $timelineStat = array_slice($this->clickHouse->queryLog(), -1)[0];

        return $this->render('events.html.twig', [
            'events' => $events,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PAGE_SIZE),
            'event_type' => $eventType,
            'time' => $time->queryParams(),
            'timeline' => $timeline,
            'timeline_stat' => $timelineStat,
            'stats' => $this->storageStats(),
        ]);
    }

    /**
     * Автопоиск аномалий: BubbleUp без выбора события. Один проход по таблице
     * через GROUP BY GROUPING SETS ((dim1),(dim2),…) даёт средние всех метрик
     * для всех групп всех измерений; PHP сравнивает каждую группу с глобальным
     * средним в сигмах (|group_avg − global_avg| / stddev). См. docs/API.md §5.
     */
    #[Route('/anomalies', methods: ['GET'])]
    public function anomalies(Request $request): Response
    {
        $time = TimeFilter::fromRequest($request);
        $sampling = Sampling::fromRequest($request);
        $fromTail = trim($sampling->sql().' '.($time->conditions() ? 'WHERE '.implode(' AND ', $time->conditions()) : ''));
        $params = $time->params();

        $avgCols = implode(', ', array_map(
            static fn (string $m): string => "avg({$m}) AS avg_{$m}",
            Schema::METRICS,
        ));
        $stdCols = implode(', ', array_map(
            static fn (string $m): string => "stddevPop({$m}) AS std_{$m}",
            Schema::METRICS,
        ));
        $global = $this->clickHouse->select(
            "SELECT count() AS n, {$avgCols}, {$stdCols} FROM events {$fromTail}",
            $params,
        )[0];

        $sets = implode(', ', array_map(static fn (string $d): string => "({$d})", Schema::DIMENSIONS));
        $groups = $this->clickHouse->select(
            'SELECT '.implode(', ', Schema::DIMENSIONS).", count() AS n, {$avgCols}
             FROM events {$fromTail}
             GROUP BY GROUPING SETS ({$sets})",
            $params,
        );

        $findings = [];
        foreach ($groups as $group) {
            // средние сэмплирование не искажает, а счётчики домножаем обратно
            if ((int) round((int) $group['n'] * $sampling->scale()) < self::ANOMALY_MIN_GROUP) {
                continue;
            }
            // в строке GROUPING SETS сгруппировано ровно одно измерение —
            // остальные приходят пустыми (у нас пустых значений не бывает)
            $dimension = null;
            foreach (Schema::DIMENSIONS as $d) {
                if ('' !== $group[$d]) {
                    $dimension = $d;
                    break;
                }
            }
            if (null === $dimension) {
                continue;
            }

            foreach (Schema::METRICS as $m) {
                $std = (float) $global["std_{$m}"];
                $globalAvg = (float) $global["avg_{$m}"];
                $groupAvg = (float) $group["avg_{$m}"];
                if ($std <= 0) {
                    continue;
                }
                $sigma = abs($groupAvg - $globalAvg) / $std;
                if ($sigma < self::ANOMALY_MIN_SIGMA) {
                    continue;
                }
                $findings[] = [
                    'dimension' => $dimension,
                    'value' => $group[$dimension],
                    'metric' => $m,
                    'group_avg' => round($groupAvg, 2),
                    'global_avg' => round($globalAvg, 2),
                    'ratio' => abs($globalAvg) > 1e-9 ? round($groupAvg / $globalAvg, 2) : null,
                    'sigma' => round($sigma, 2),
                    'n' => (int) round((int) $group['n'] * $sampling->scale()),
                ];
            }
        }
        usort($findings, static fn (array $a, array $b): int => $b['sigma'] <=> $a['sigma']);

        // Не больше 3 значений на пару измерение×метрика: иначе структурные
        // корреляции (все 11 фургонов экономичнее грузовиков) вытесняют всё
        $perPair = [];
        $findings = array_values(array_filter($findings, static function (array $f) use (&$perPair): bool {
            $key = $f['dimension'].'|'.$f['metric'];
            $perPair[$key] = ($perPair[$key] ?? 0) + 1;

            return $perPair[$key] <= 3;
        }));

        return $this->render('anomalies.html.twig', [
            'findings' => \array_slice($findings, 0, 60),
            'total' => (int) round((int) $global['n'] * $sampling->scale()),
            'time' => $time->queryParams(),
            'sample' => $sampling->rate,
            'queries' => $this->clickHouse->queryLog(),
        ]);
    }

    /**
     * Переход из таблицы аномалий к исследованию: находит свежее событие
     * группы и открывает его страницу с нужным измерением сходства.
     */
    #[Route('/explore/{dimension}/{value}', methods: ['GET'])]
    public function explore(string $dimension, string $value, Request $request): Response
    {
        if (!Schema::isDimension($dimension)) {
            throw $this->createNotFoundException();
        }
        $time = TimeFilter::fromRequest($request);
        $where = [...$time->conditions(), "{$dimension} = {value:String}"];

        $rows = $this->clickHouse->select(
            'SELECT toString(event_id) AS id FROM events
             WHERE '.implode(' AND ', $where).'
             ORDER BY toStartOfHour(event_time) DESC, event_time DESC LIMIT 1',
            $time->params() + ['value' => $value],
        );
        if (!$rows) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('app_page_event', [
            'id' => $rows[0]['id'],
            'similar_by' => $dimension,
        ] + $time->queryParams());
    }

    /**
     * Статистика хранения для блока на главной: сколько строк и байт лежит
     * в активных кусках MergeTree (system.parts) и во что примерно вылился бы
     * экспорт в JSONEachRow (средний размер JSON-строки по выборке × строки).
     *
     * @return array<string, int|float>
     */
    private function storageStats(): array
    {
        $parts = $this->clickHouse->select(
            "SELECT sum(rows) AS rows,
                    sum(bytes_on_disk) AS disk_bytes,
                    sum(data_uncompressed_bytes) AS uncompressed_bytes
             FROM system.parts
             WHERE database = currentDatabase() AND table = 'events' AND active",
        )[0];

        $avgJsonRow = (int) $this->clickHouse->select(
            "SELECT ifNull(round(avg(length(formatRow('JSONEachRow', *)))), 0) AS b
             FROM (SELECT * FROM events LIMIT 1000)",
        )[0]['b'];

        $rows = (int) $parts['rows'];

        return [
            'rows' => $rows,
            'fields' => 2 + \count(Schema::DIMENSIONS) + \count(Schema::METRICS),
            'dimensions' => \count(Schema::DIMENSIONS),
            'metrics' => \count(Schema::METRICS),
            'disk_mb' => (int) $parts['disk_bytes'] / 1024 / 1024,
            'uncompressed_mb' => (int) $parts['uncompressed_bytes'] / 1024 / 1024,
            'avg_json_row' => $avgJsonRow,
            'json_export_mb' => $rows * $avgJsonRow / 1024 / 1024,
        ];
    }

    #[Route('/event/{id}', methods: ['GET'])]
    public function event(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }
        // greatCircleDistance — гео-функция ClickHouse (метры по дуге большого круга)
        $rows = $this->clickHouse->select(
            'SELECT *, round(greatCircleDistance(lon, lat, 24.10, 56.95) / 1000, 1) AS distance_to_riga_km
             FROM events WHERE event_id = {id:UUID} LIMIT 1',
            ['id' => $id],
        );
        if (!$rows) {
            throw $this->createNotFoundException();
        }

        $similarBy = (string) $request->query->get('similar_by', self::DEFAULT_SIMILAR_BY);
        if (!Schema::isDimension($similarBy)) {
            $similarBy = self::DEFAULT_SIMILAR_BY;
        }

        $chartFields = array_values(array_diff(
            [...Schema::DIMENSIONS, ...Schema::METRICS],
            [$similarBy],
        ));

        return $this->render('event.html.twig', [
            'event' => $rows[0],
            'similar_by' => $similarBy,
            'dimensions' => Schema::DIMENSIONS,
            'chart_fields' => $chartFields,
            'time' => TimeFilter::fromRequest($request)->queryParams(),
            'sample' => Sampling::fromRequest($request)->rate,
        ]);
    }
}
