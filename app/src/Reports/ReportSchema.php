<?php

namespace App\Reports;

/**
 * Описание формата конфигурации репорта (docs/REPORTS.md §4).
 * Единственный источник правды для валидатора, форм редактора и промпта ИИ.
 */
final class ReportSchema
{
    /**
     * fn => требование к полю: metric | dimension | none.
     * 'filters' => true — агрегация несёт собственный список фильтров (count_if).
     * 'scalable' => true — счётная агрегация, при сэмплировании домножается на 1/rate.
     */
    public const AGGREGATIONS = [
        'count' => ['field' => 'none', 'scalable' => true],
        'uniq' => ['field' => 'dimension'],
        'sum' => ['field' => 'metric', 'scalable' => true],
        'avg' => ['field' => 'metric'],
        'min' => ['field' => 'metric'],
        'max' => ['field' => 'metric'],
        'median' => ['field' => 'metric'],
        'p95' => ['field' => 'metric'],
        'count_if' => ['field' => 'none', 'filters' => true, 'scalable' => true],
        'max_minus_min' => ['field' => 'metric'],
    ];

    public const DIMENSION_OPS = ['=', '!=', 'in', 'not_in'];
    public const METRIC_OPS = ['=', '!=', '>', '>=', '<', '<=', 'between'];

    public const TIME_BUCKETS = ['hour', 'day', 'week', 'month'];

    /**
     * Правила совместимости визуализации с query (проверяет валидатор):
     * time_bucket: true — обязателен, false — запрещён, null — любой;
     * group_by: [min, max]; max_aggregations: int|null.
     */
    public const VIZ = [
        'table' => ['time_bucket' => null, 'group_by' => [0, 2], 'max_aggregations' => null],
        'line' => ['time_bucket' => true, 'group_by' => [0, 1], 'max_aggregations' => null],
        'bar' => ['time_bucket' => false, 'group_by' => [1, 1], 'max_aggregations' => null],
        'stacked_bar' => ['time_bucket' => true, 'group_by' => [1, 1], 'max_aggregations' => 1],
        'stat' => ['time_bucket' => false, 'group_by' => [0, 0], 'max_aggregations' => 1],
        'heatmap' => ['time_bucket' => true, 'group_by' => [1, 1], 'max_aggregations' => 1],
    ];

    public const ALIAS_PATTERN = '/^[a-z][a-z0-9_]{0,30}$/';
    public const SAMPLES = [0.1, 0.01];
    public const MAX_WIDGETS = 12;
    public const MAX_FILTERS = 10;
    public const MAX_AGGREGATIONS = 5;
    public const MAX_LIMIT = 1000;
    public const MAX_LAST_HOURS = 8760; // год

    /** Всё описание одним массивом — для форм редактора и промпта ИИ. */
    public static function describe(): array
    {
        return [
            'aggregations' => self::AGGREGATIONS,
            'dimension_ops' => self::DIMENSION_OPS,
            'metric_ops' => self::METRIC_OPS,
            'time_buckets' => self::TIME_BUCKETS,
            'viz' => self::VIZ,
            'samples' => self::SAMPLES,
        ];
    }
}
