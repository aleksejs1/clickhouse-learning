<?php

namespace App\Service;

/**
 * Хранилище конфигов (репорты, алерты) поверх ReplacingMergeTree.
 *
 * Правила (docs/REPORTS.md §3): чтение — всегда FINAL + is_deleted = 0;
 * обновление — INSERT новой версии с бОльшим updated_at; удаление — soft
 * delete. Слияние дублей происходит фоном, FINAL схлопывает их при чтении.
 */
class ConfigStore
{
    private const TABLES = ['report_configs', 'alert_configs'];

    public function __construct(private ClickHouse $clickHouse)
    {
    }

    /** @return list<array{id: string, name: string, config: string, updated_at: string}> */
    public function list(string $table): array
    {
        return $this->clickHouse->select(
            "SELECT toString(id) AS id, name, config, toString(updated_at) AS updated_at
             FROM {$this->table($table)} FINAL
             WHERE is_deleted = 0
             ORDER BY updated_at DESC",
        );
    }

    /** @return array{id: string, name: string, config: string, updated_at: string}|null */
    public function get(string $table, string $id): ?array
    {
        // алиас toString(id) AS id затеняет колонку в WHERE — квалифицируем
        // колонку именем таблицы, иначе String сравнивается с UUID (NO_COMMON_TYPE)
        $t = $this->table($table);
        $rows = $this->clickHouse->select(
            "SELECT toString(id) AS id, name, config, toString(updated_at) AS updated_at
             FROM {$t} FINAL
             WHERE {$t}.id = {id:UUID} AND is_deleted = 0
             LIMIT 1",
            ['id' => $id],
        );

        return $rows[0] ?? null;
    }

    public function save(string $table, string $id, string $name, array $config): void
    {
        $this->insert($this->table($table), $id, $name, $config, 0);
    }

    public function delete(string $table, string $id): void
    {
        $existing = $this->get($table, $id);
        if (null === $existing) {
            return;
        }
        $this->insert($this->table($table), $id, $existing['name'], json_decode($existing['config'], true) ?? [], 1);
    }

    private function insert(string $table, string $id, string $name, array $config, int $isDeleted): void
    {
        $this->clickHouse->insertBatch($table, [[
            'id' => $id,
            'name' => $name,
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'is_deleted' => $isDeleted,
            'updated_at' => date('Y-m-d H:i:s'),
        ]]);
    }

    private function table(string $table): string
    {
        if (!\in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException("unknown config table: {$table}");
        }

        return $table;
    }
}
