<?php

namespace App\Controller;

use App\Alerts\NodeCatalog;
use App\Alerts\AlertSummary;
use App\Schema;
use App\Service\ConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class AlertPageController extends AbstractController
{
    private const UUID_RE = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private ConfigStore $store,
        private AlertSummary $summary,
    ) {
    }

    #[Route('/alerts', methods: ['GET'])]
    public function list(): Response
    {
        $alerts = array_map(function (array $row): array {
            $config = json_decode($row['config'], true) ?? [];

            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'enabled' => (bool) ($config['enabled'] ?? true),
                'summary' => $this->summary->build($config),
                'updated_at' => $row['updated_at'],
            ];
        }, $this->store->list('alert_configs'));

        return $this->render('alerts.html.twig', ['alerts' => $alerts]);
    }

    #[Route('/alert/new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->editor(null);
    }

    #[Route('/alert/{id}/edit', requirements: ['id' => self::UUID_RE], methods: ['GET'])]
    public function edit(string $id): Response
    {
        return $this->editor($id);
    }

    private function editor(?string $id): Response
    {
        $config = ['version' => 1, 'name' => 'Новый алерт', 'description' => '', 'enabled' => true, 'nodes' => [], 'edges' => []];
        if (null !== $id) {
            if (!Uuid::isValid($id)) {
                throw $this->createNotFoundException();
            }
            $row = $this->store->get('alert_configs', $id) ?? throw $this->createNotFoundException();
            $config = json_decode($row['config'], true);
        }

        return $this->render('alert_edit.html.twig', [
            'id' => $id,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'catalog_json' => json_encode(NodeCatalog::all(), JSON_UNESCAPED_UNICODE),
            'fields_json' => json_encode([
                'dimensions' => Schema::DIMENSIONS,
                'metrics' => Schema::METRICS,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
