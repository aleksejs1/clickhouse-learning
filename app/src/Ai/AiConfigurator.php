<?php

namespace App\Ai;

use Anthropic\Client;
use App\Alerts\AlertSummary;
use App\Alerts\AlertValidator;
use App\Reports\ReportValidator;

/**
 * Генерация конфигов репортов и алертов по свободному тексту
 * (docs/AI_ASSISTANT.md §3). Structured outputs гарантируют форму ответа;
 * серверный валидатор замыкает петлю: при ошибке — один ретрай с их текстом.
 */
class AiConfigurator
{
    private const MAX_TOKENS = 16000;

    public function __construct(
        private string $apiKey,
        private string $model,
        private PromptBuilder $prompts,
        private ReportValidator $reportValidator,
        private AlertValidator $alertValidator,
        private AlertSummary $alertSummary,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->apiKey;
    }

    /**
     * @param list<array{role: string, content: string}> $history
     *
     * @return array{reply: string, config: array}
     */
    public function generateReport(string $prompt, array $history, ?array $currentConfig): array
    {
        return $this->generate(
            $this->prompts->report(),
            $this->reportResponseSchema(),
            fn (array $config): array => $this->reportValidator->validate($config),
            $prompt, $history, $currentConfig,
        );
    }

    /**
     * @param list<array{role: string, content: string}> $history
     *
     * @return array{reply: string, config: array, summary: string}
     */
    public function generateAlert(string $prompt, array $history, ?array $currentConfig): array
    {
        $result = $this->generate(
            $this->prompts->alert(),
            $this->alertResponseSchema(),
            fn (array $config): array => array_map(
                static fn (array $e): string => ($e['node'] ? $e['node'].': ' : '').$e['message'],
                $this->alertValidator->validate($config),
            ),
            $prompt, $history, $currentConfig,
        );
        $result['summary'] = $this->alertSummary->build($result['config']);

        return $result;
    }

    /**
     * @param callable(array): list<string> $validate
     *
     * @return array{reply: string, config: array}
     */
    private function generate(string $system, array $schema, callable $validate, string $prompt, array $history, ?array $currentConfig): array
    {
        $client = new Client(apiKey: $this->apiKey);

        $messages = [];
        foreach ($history as $m) {
            $messages[] = ['role' => $m['role'], 'content' => (string) $m['content']];
        }
        $userText = $prompt;
        if (null !== $currentConfig) {
            $userText = "Текущая конфигурация (правь её, а не создавай заново):\n"
                .json_encode($currentConfig, JSON_UNESCAPED_UNICODE)."\n\nЗапрос: ".$prompt;
        }
        $messages[] = ['role' => 'user', 'content' => $userText];

        // до 2 попыток: исходная + один ретрай с ошибками валидатора
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $response = $client->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: $messages,
                model: $this->model,
                thinking: ['type' => 'adaptive'],
                system: [[
                    'type' => 'text',
                    'text' => $system,
                    'cacheControl' => ['type' => 'ephemeral'], // system большой и стабильный
                ]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
            );

            $this->logUsage($response);
            $json = $this->extractText($response);
            $parsed = json_decode($json, true);
            if (!\is_array($parsed) || !isset($parsed['config'])) {
                throw new \RuntimeException('ИИ вернул неожиданный формат ответа');
            }

            $errors = $validate($parsed['config']);
            if ([] === $errors) {
                return ['reply' => (string) ($parsed['reply'] ?? ''), 'config' => $parsed['config']];
            }

            // ретрай: показываем модели её ответ и ошибки валидатора
            $messages[] = ['role' => 'assistant', 'content' => $json];
            $messages[] = ['role' => 'user', 'content' =>
                "Конфигурация не прошла валидацию:\n- ".implode("\n- ", $errors)
                ."\nИсправь и верни конфиг заново."];
        }

        throw new AiValidationException($errors);
    }

    private function extractText(object $response): string
    {
        foreach ($response->content as $block) {
            if ('text' === ($block->type ?? null)) {
                return $block->text;
            }
        }

        throw new \RuntimeException('ИИ не вернул текстовый блок');
    }

    private function logUsage(object $response): void
    {
        $u = $response->usage ?? null;
        if (null !== $u) {
            error_log(sprintf('[AI] input=%d output=%d cache_read=%d stop=%s',
                $u->inputTokens ?? 0, $u->outputTokens ?? 0,
                $u->cacheReadInputTokens ?? 0, $response->stopReason ?? '?'));
        }
    }

    /**
     * JSON-схема ответа репорта. Строгая настолько, насколько позволяет
     * structured outputs; тонкие правила (viz↔query) ловит серверный валидатор.
     */
    private function reportResponseSchema(): array
    {
        $field = ['type' => 'object', 'properties' => [
            'field' => ['type' => 'string'],
            'op' => ['type' => 'string'],
            'value' => ['type' => ['string', 'number', 'array', 'boolean']],
        ], 'required' => ['field', 'op', 'value'], 'additionalProperties' => false];

        $agg = ['type' => 'object', 'properties' => [
            'fn' => ['type' => 'string', 'enum' => array_keys(\App\Reports\ReportSchema::AGGREGATIONS)],
            'field' => ['type' => 'string'],
            'alias' => ['type' => 'string'],
            'filters' => ['type' => 'array', 'items' => $field],
        ], 'required' => ['fn', 'alias'], 'additionalProperties' => false];

        $query = ['type' => 'object', 'properties' => [
            'time_range' => ['type' => 'object'],
            'filters' => ['type' => 'array', 'items' => $field],
            'group_by' => ['type' => 'array', 'items' => ['type' => 'string']],
            'time_bucket' => ['type' => ['string', 'null']],
            'aggregations' => ['type' => 'array', 'items' => $agg],
            'sort' => ['type' => 'object'],
            'limit' => ['type' => 'integer'],
            'sample' => ['type' => ['number', 'null']],
            'top_n_other' => ['type' => 'boolean'],
            'compare_previous_period' => ['type' => 'boolean'],
        ], 'required' => ['time_range', 'aggregations'], 'additionalProperties' => false];

        $widget = ['type' => 'object', 'properties' => [
            'title' => ['type' => 'string'],
            'width' => ['type' => 'integer', 'enum' => [1, 2, 3]],
            'query' => $query,
            'viz' => ['type' => 'object', 'properties' => [
                'type' => ['type' => 'string', 'enum' => array_keys(\App\Reports\ReportSchema::VIZ)],
            ], 'required' => ['type'], 'additionalProperties' => false],
        ], 'required' => ['title', 'width', 'query', 'viz'], 'additionalProperties' => false];

        return $this->envelope(['type' => 'object', 'properties' => [
            'version' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'widgets' => ['type' => 'array', 'items' => $widget],
        ], 'required' => ['name', 'widgets'], 'additionalProperties' => false]);
    }

    private function alertResponseSchema(): array
    {
        $types = array_map(static fn (array $n): string => $n['type'], \App\Alerts\NodeCatalog::all());

        $node = ['type' => 'object', 'properties' => [
            'id' => ['type' => 'string'],
            'type' => ['type' => 'string', 'enum' => $types],
            'position' => ['type' => 'object', 'properties' => [
                'x' => ['type' => 'number'], 'y' => ['type' => 'number'],
            ], 'required' => ['x', 'y'], 'additionalProperties' => false],
            'params' => ['type' => 'object'],
        ], 'required' => ['id', 'type', 'params'], 'additionalProperties' => false];

        $edge = ['type' => 'object', 'properties' => [
            'from' => ['type' => 'string'],
            'to' => ['type' => 'string'],
            'from_port' => ['type' => 'string', 'enum' => ['true', 'false']],
        ], 'required' => ['from', 'to'], 'additionalProperties' => false];

        return $this->envelope(['type' => 'object', 'properties' => [
            'version' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'enabled' => ['type' => 'boolean'],
            'nodes' => ['type' => 'array', 'items' => $node],
            'edges' => ['type' => 'array', 'items' => $edge],
        ], 'required' => ['name', 'nodes', 'edges'], 'additionalProperties' => false]);
    }

    private function envelope(array $configSchema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reply' => ['type' => 'string', 'description' => 'Короткий ответ пользователю по-русски'],
                'config' => $configSchema,
            ],
            'required' => ['reply', 'config'],
            'additionalProperties' => false,
        ];
    }
}
