<?php

namespace App\Controller;

use App\Ai\AiConfigurator;
use App\Ai\AiValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ИИ-эндпоинты (docs/AI_ASSISTANT.md §4). Без ключа — 503; невалидный ответ
 * ИИ после ретрая — 422; ошибки Anthropic API — 502.
 */
#[Route('/api/ai')]
class AiController extends AbstractController
{
    public function __construct(private AiConfigurator $ai)
    {
    }

    #[Route('/report', methods: ['POST'])]
    public function report(Request $request): JsonResponse
    {
        return $this->run($request, 'report');
    }

    #[Route('/alert', methods: ['POST'])]
    public function alert(Request $request): JsonResponse
    {
        return $this->run($request, 'alert');
    }

    private function run(Request $request, string $kind): JsonResponse
    {
        if (!$this->ai->isEnabled()) {
            return $this->json(['error' => 'AI не настроен: задайте ANTHROPIC_API_KEY в app/.env.local'], 503);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $prompt = trim((string) ($body['prompt'] ?? ''));
        if ('' === $prompt) {
            return $this->json(['error' => 'prompt обязателен'], 400);
        }
        $history = \is_array($body['history'] ?? null) ? $body['history'] : [];
        $current = $body['current_config'] ?? null;

        try {
            $result = 'report' === $kind
                ? $this->ai->generateReport($prompt, $history, \is_array($current) ? $current : null)
                : $this->ai->generateAlert($prompt, $history, \is_array($current) ? $current : null);

            return $this->json($result);
        } catch (AiValidationException $e) {
            return $this->json(['error' => 'ИИ не смог собрать валидную конфигурацию', 'errors' => $e->errors], 422);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Ошибка обращения к ИИ: '.$e->getMessage()], 502);
        }
    }
}
