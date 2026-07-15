<?php

namespace App\Controller;

use App\Reports\ReportRunner;
use App\Reports\ReportSchema;
use App\Reports\ReportValidator;
use App\Service\ConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api')]
class ReportApiController extends AbstractController
{
    private const TABLE = 'report_configs';

    public function __construct(
        private ConfigStore $store,
        private ReportValidator $validator,
        private ReportRunner $runner,
    ) {
    }

    /**
     * Выполнить query-конфиг одного виджета (docs/REPORTS.md §6).
     * Тело: {"query": {...}, "viz_type": "bar"}.
     */
    #[Route('/report-data', methods: ['POST'])]
    public function data(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $vizType = (string) ($body['viz_type'] ?? 'table');
        if (!isset(ReportSchema::VIZ[$vizType])) {
            return $this->json(['errors' => ['viz_type: допустимо '.implode(', ', array_keys(ReportSchema::VIZ))]], 422);
        }
        $errors = $this->validator->validateQuery($body['query'] ?? null, $vizType);
        if ($errors) {
            return $this->json(['errors' => $errors], 422);
        }

        return $this->json($this->runner->run($body['query'], $vizType));
    }

    #[Route('/reports', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(static fn (array $row) => [
            'id' => $row['id'],
            'name' => $row['name'],
            'description' => json_decode($row['config'], true)['description'] ?? '',
            'updated_at' => $row['updated_at'],
        ], $this->store->list(self::TABLE)));
    }

    #[Route('/reports', methods: ['POST'])]
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

    #[Route('/reports/{id}', methods: ['GET'])]
    public function one(string $id): JsonResponse
    {
        $row = $this->find($id);

        return $this->json(['id' => $row['id'], 'updated_at' => $row['updated_at']]
            + ['config' => json_decode($row['config'], true)]);
    }

    #[Route('/reports/{id}', methods: ['PUT'])]
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

    #[Route('/reports/{id}', methods: ['DELETE'])]
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
