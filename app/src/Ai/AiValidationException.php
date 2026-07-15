<?php

namespace App\Ai;

/**
 * ИИ дважды вернул конфиг, не прошедший серверную валидацию.
 * Контроллер отдаёт errors в чат (docs/AI_ASSISTANT.md §3, §4).
 */
class AiValidationException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('AI config failed validation after retry');
    }
}
