<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Глобальная переменная ai_enabled для шаблонов: чат-панели ИИ рендерятся,
 * только если задан ANTHROPIC_API_KEY (docs/AI_ASSISTANT.md §2).
 */
class AiEnabledExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private string $apiKey)
    {
    }

    public function getGlobals(): array
    {
        return ['ai_enabled' => '' !== $this->apiKey];
    }
}
