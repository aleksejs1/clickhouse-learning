<?php

namespace App\Controller;

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

        $events = $this->clickHouse->select(
            "SELECT * FROM events {$whereSql}
             ORDER BY event_time DESC
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
        ]);
    }
}
