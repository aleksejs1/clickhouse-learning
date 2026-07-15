<?php

namespace App\Alerts;

use App\Schema;

/**
 * Каталог типов узлов конструктора алертов (docs/ALERTS.md §4).
 * Единственный источник для палитры UI, валидатора и промпта ИИ.
 *
 * Типы параметров: string, number, bool, enum, cron, dimension_field,
 * metric_field, filters (массив фильтров формата репортов), template
 * (строка с плейсхолдерами {{...}}).
 */
final class NodeCatalog
{
    /** @return list<array> каталог с подставленными значениями полей из Schema */
    public static function all(): array
    {
        return array_map(static function (array $node): array {
            foreach ($node['params'] as &$p) {
                if ('dimension_field' === $p['type']) {
                    $p['values'] = Schema::DIMENSIONS;
                } elseif ('metric_field' === $p['type']) {
                    $p['values'] = Schema::METRICS;
                }
            }

            return $node;
        }, self::NODES);
    }

    /** @return array<string, array>|null схема одного типа узла */
    public static function get(string $type): ?array
    {
        foreach (self::all() as $node) {
            if ($node['type'] === $type) {
                return $node;
            }
        }

        return null;
    }

    private const NODES = [
        // ---- Триггеры (inputs: 0) ----
        [
            'type' => 'metric_threshold', 'category' => 'trigger', 'label' => 'Порог метрики',
            'inputs' => 0, 'outputs' => 1,
            'params' => [
                ['name' => 'metric', 'type' => 'metric_field', 'required' => true],
                ['name' => 'agg', 'type' => 'enum', 'values' => ['avg', 'min', 'max', 'sum', 'count'], 'default' => 'avg'],
                ['name' => 'op', 'type' => 'enum', 'values' => ['>', '>=', '<', '<='], 'required' => true],
                ['name' => 'value', 'type' => 'number', 'required' => true],
                ['name' => 'window_minutes', 'type' => 'number', 'default' => 15],
                ['name' => 'group_by', 'type' => 'dimension_field', 'default' => 'vehicle_id'],
            ],
        ],
        [
            'type' => 'anomaly_deviation', 'category' => 'trigger', 'label' => 'Отклонение группы (σ)',
            'inputs' => 0, 'outputs' => 1,
            'params' => [
                ['name' => 'dimension', 'type' => 'dimension_field', 'required' => true],
                ['name' => 'metric', 'type' => 'metric_field', 'required' => true],
                ['name' => 'sigma_threshold', 'type' => 'number', 'default' => 3],
                ['name' => 'window_hours', 'type' => 'number', 'default' => 24],
            ],
        ],
        [
            'type' => 'fuel_drop', 'category' => 'trigger', 'label' => 'Слив топлива',
            'inputs' => 0, 'outputs' => 1,
            'params' => [
                ['name' => 'drop_pct', 'type' => 'number', 'default' => 15],
                ['name' => 'window_minutes', 'type' => 'number', 'default' => 10],
            ],
        ],
        [
            'type' => 'no_data', 'category' => 'trigger', 'label' => 'Нет данных',
            'inputs' => 0, 'outputs' => 1,
            'params' => [
                ['name' => 'group_by', 'type' => 'dimension_field', 'default' => 'vehicle_id'],
                ['name' => 'silence_minutes', 'type' => 'number', 'default' => 30],
            ],
        ],
        [
            'type' => 'geofence', 'category' => 'trigger', 'label' => 'Геозона',
            'inputs' => 0, 'outputs' => 1,
            'params' => [
                ['name' => 'lat', 'type' => 'number', 'required' => true],
                ['name' => 'lon', 'type' => 'number', 'required' => true],
                ['name' => 'radius_km', 'type' => 'number', 'default' => 5],
                ['name' => 'direction', 'type' => 'enum', 'values' => ['enter', 'exit'], 'default' => 'exit'],
                ['name' => 'group_by', 'type' => 'dimension_field', 'default' => 'vehicle_id'],
            ],
        ],
        [
            'type' => 'odometer_milestone', 'category' => 'trigger', 'label' => 'Пробег (ТО)',
            'inputs' => 0, 'outputs' => 1,
            'params' => [
                ['name' => 'every_km', 'type' => 'number', 'default' => 15000],
            ],
        ],
        [
            'type' => 'schedule', 'category' => 'trigger', 'label' => 'По расписанию',
            'inputs' => 0, 'outputs' => 1,
            'params' => [
                ['name' => 'cron', 'type' => 'cron', 'required' => true, 'default' => '0 8 * * *'],
            ],
        ],

        // ---- Условия и обработка (inputs: 1) ----
        [
            'type' => 'filter', 'category' => 'condition', 'label' => 'Фильтр',
            'inputs' => 1, 'outputs' => 1,
            'params' => [
                ['name' => 'filters', 'type' => 'filters', 'required' => true],
            ],
        ],
        [
            'type' => 'condition', 'category' => 'condition', 'label' => 'Ветвление',
            'inputs' => 1, 'outputs' => 2, 'ports' => ['true', 'false'],
            'params' => [
                ['name' => 'field', 'type' => 'metric_field', 'required' => true],
                ['name' => 'op', 'type' => 'enum', 'values' => ['>', '>=', '<', '<=', '=', '!='], 'required' => true],
                ['name' => 'value', 'type' => 'number', 'required' => true],
            ],
        ],
        [
            'type' => 'time_window', 'category' => 'condition', 'label' => 'Временное окно',
            'inputs' => 1, 'outputs' => 1,
            'params' => [
                ['name' => 'days_of_week', 'type' => 'enum_multi', 'values' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'default' => ['mon', 'tue', 'wed', 'thu', 'fri']],
                ['name' => 'from_time', 'type' => 'string', 'default' => '08:00'],
                ['name' => 'to_time', 'type' => 'string', 'default' => '18:00'],
            ],
        ],
        [
            'type' => 'dedup', 'category' => 'condition', 'label' => 'Не чаще чем',
            'inputs' => 1, 'outputs' => 1,
            'params' => [
                ['name' => 'cooldown_minutes', 'type' => 'number', 'default' => 60],
            ],
        ],
        [
            'type' => 'severity', 'category' => 'condition', 'label' => 'Важность',
            'inputs' => 1, 'outputs' => 1,
            'params' => [
                ['name' => 'level', 'type' => 'enum', 'values' => ['info', 'warning', 'critical'], 'default' => 'warning'],
            ],
        ],
        [
            'type' => 'digest', 'category' => 'condition', 'label' => 'Дайджест',
            'inputs' => 1, 'outputs' => 1,
            'params' => [
                ['name' => 'period_minutes', 'type' => 'number', 'default' => 60],
            ],
        ],

        // ---- Действия (outputs: 0) ----
        [
            'type' => 'notify_email', 'category' => 'action', 'label' => 'Email',
            'inputs' => 1, 'outputs' => 0,
            'params' => [
                ['name' => 'to', 'type' => 'string', 'required' => true],
                ['name' => 'subject', 'type' => 'template', 'required' => true],
                ['name' => 'body', 'type' => 'template', 'default' => ''],
            ],
        ],
        [
            'type' => 'notify_sms', 'category' => 'action', 'label' => 'SMS',
            'inputs' => 1, 'outputs' => 0,
            'params' => [
                ['name' => 'phone', 'type' => 'string', 'required' => true],
                ['name' => 'text', 'type' => 'template', 'required' => true],
            ],
        ],
        [
            'type' => 'notify_telegram', 'category' => 'action', 'label' => 'Telegram',
            'inputs' => 1, 'outputs' => 0,
            'params' => [
                ['name' => 'chat_id', 'type' => 'string', 'required' => true],
                ['name' => 'text', 'type' => 'template', 'required' => true],
            ],
        ],
        [
            'type' => 'webhook', 'category' => 'action', 'label' => 'Webhook',
            'inputs' => 1, 'outputs' => 0,
            'params' => [
                ['name' => 'url', 'type' => 'string', 'required' => true],
                ['name' => 'method', 'type' => 'enum', 'values' => ['GET', 'POST'], 'default' => 'POST'],
                ['name' => 'payload', 'type' => 'template', 'default' => ''],
            ],
        ],
        [
            'type' => 'create_ticket', 'category' => 'action', 'label' => 'Создать тикет',
            'inputs' => 1, 'outputs' => 0,
            'params' => [
                ['name' => 'queue', 'type' => 'string', 'required' => true],
                ['name' => 'title', 'type' => 'template', 'required' => true],
                ['name' => 'description', 'type' => 'template', 'default' => ''],
            ],
        ],
        [
            'type' => 'escalate', 'category' => 'action', 'label' => 'Эскалация',
            'inputs' => 1, 'outputs' => 0,
            'params' => [
                ['name' => 'after_minutes', 'type' => 'number', 'default' => 60],
                ['name' => 'to', 'type' => 'string', 'required' => true],
            ],
        ],
    ];
}
