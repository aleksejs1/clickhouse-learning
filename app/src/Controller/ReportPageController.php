<?php

namespace App\Controller;

use App\Reports\ReportSchema;
use App\Schema;
use App\Service\ConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

class ReportPageController extends AbstractController
{
    private const UUID_RE = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private ConfigStore $store)
    {
    }

    #[Route('/reports', methods: ['GET'])]
    public function list(): Response
    {
        $reports = array_map(static fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'description' => json_decode($row['config'], true)['description'] ?? '',
            'updated_at' => $row['updated_at'],
        ], $this->store->list('report_configs'));

        return $this->render('reports.html.twig', ['reports' => $reports]);
    }

    #[Route('/report/new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->editor(null);
    }

    #[Route('/report/{id}/edit', requirements: ['id' => self::UUID_RE], methods: ['GET'])]
    public function edit(string $id): Response
    {
        return $this->editor($id);
    }

    #[Route('/report/{id}', requirements: ['id' => self::UUID_RE], methods: ['GET'])]
    public function view(string $id): Response
    {
        $row = $this->find($id);
        $config = json_decode($row['config'], true);

        return $this->render('report_view.html.twig', [
            'id' => $id,
            'name' => $row['name'],
            'description' => $config['description'] ?? '',
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function editor(?string $id): Response
    {
        $config = [
            'version' => 1,
            'name' => 'Новый репорт',
            'description' => '',
            'widgets' => [],
        ];
        if (null !== $id) {
            $config = json_decode($this->find($id)['config'], true);
        }

        return $this->render('report_edit.html.twig', [
            'id' => $id,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'schema_json' => json_encode([
                'dimensions' => Schema::DIMENSIONS,
                'metrics' => Schema::METRICS,
            ] + ReportSchema::describe(), JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array{id: string, name: string, config: string, updated_at: string} */
    private function find(string $id): array
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        return $this->store->get('report_configs', $id) ?? throw $this->createNotFoundException();
    }
}
