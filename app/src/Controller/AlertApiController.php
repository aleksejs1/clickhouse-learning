<?php

namespace App\Controller;

use App\Alerts\AlertSummary;
use App\Alerts\AlertValidator;
use App\Alerts\NodeCatalog;
use App\Service\ConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api')]
class AlertApiController extends AbstractController
{
    private const TABLE = 'alert_configs';

    public function __construct(
        private ConfigStore $store,
        private AlertValidator $validator,
        private AlertSummary $summary,
    ) {
    }

    /** Каталог узлов для палитры UI и промпта ИИ (docs/ALERTS.md §4). */
    #[Route('/alert-nodes', methods: ['GET'])]
    public function nodes(): JsonResponse
    {
        return $this->json(NodeCatalog::all());
    }

    /** Проверка графа без сохранения (docs/ALERTS.md §5). */
    #[Route('/alerts/validate', methods: ['POST'])]
    public function validate(Request $request): JsonResponse
    {
        $config = json_decode($request->getContent(), true);
        $errors = $this->validator->validate($config);

        return $this->json([
            'valid' => 0 === \count($errors),
            'errors' => $errors,
            'summary' => \is_array($config) ? $this->summary->build($config) : '',
        ]);
    }

    #[Route('/alerts', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(function (array $row): array {
            $config = json_decode($row['config'], true) ?? [];

            return [
                'id' => $row['id'],
                'name' => $row['name'],
                'enabled' => (bool) ($config['enabled'] ?? true),
                'summary' => $this->summary->build($config),
                'updated_at' => $row['updated_at'],
            ];
        }, $this->store->list(self::TABLE)));
    }

    #[Route('/alerts', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $config = json_decode($request->getContent(), true);
        $errors = $this->validator->validate($config);
        if ($errors) {
            return $this->json(['errors' => $errors], 422);
        }
        $id = Uuid::v4()->toRfc4122();
        $this->store->save(self::TABLE, $id, $config['name'], $config);

        return $this->json(['id' => $id], 201);
    }

    #[Route('/alerts/{id}', methods: ['GET'])]
    public function one(string $id): JsonResponse
    {
        $row = $this->find($id);

        return $this->json([
            'id' => $row['id'],
            'updated_at' => $row['updated_at'],
            'config' => json_decode($row['config'], true),
        ]);
    }

    #[Route('/alerts/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->find($id);
        $config = json_decode($request->getContent(), true);
        $errors = $this->validator->validate($config);
        if ($errors) {
            return $this->json(['errors' => $errors], 422);
        }
        $this->store->save(self::TABLE, $id, $config['name'], $config);

        return $this->json(['id' => $id]);
    }

    #[Route('/alerts/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $this->find($id);
        $this->store->delete(self::TABLE, $id);

        return $this->json(['ok' => true]);
    }

    /** @return array{id: string, name: string, config: string, updated_at: string} */
    private function find(string $id): array
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        return $this->store->get(self::TABLE, $id) ?? throw $this->createNotFoundException();
    }
}
