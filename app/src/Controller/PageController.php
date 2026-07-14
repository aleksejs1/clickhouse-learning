<?php

namespace App\Controller;

use App\Schema;
use App\Service\ClickHouse;
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

        $whereSql = '' !== $eventType ? 'WHERE event_type = {event_type:String}' : '';
        $params = '' !== $eventType ? ['event_type' => $eventType] : [];

        $events = $this->clickHouse->select(
            "SELECT * FROM events {$whereSql}
             ORDER BY event_time DESC
             LIMIT {limit:UInt32} OFFSET {offset:UInt32}",
            $params + ['limit' => self::PAGE_SIZE, 'offset' => $page * self::PAGE_SIZE],
        );
        $total = (int) $this->clickHouse->select("SELECT count() AS c FROM events {$whereSql}", $params)[0]['c'];

        return $this->render('events.html.twig', [
            'events' => $events,
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PAGE_SIZE),
            'event_type' => $eventType,
        ]);
    }

    #[Route('/event/{id}', methods: ['GET'])]
    public function event(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }
        $rows = $this->clickHouse->select(
            'SELECT * FROM events WHERE event_id = {id:UUID} LIMIT 1',
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
        ]);
    }
}
