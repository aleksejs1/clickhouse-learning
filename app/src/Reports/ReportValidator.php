<?php

namespace App\Reports;

use App\Schema;

/**
 * Валидация конфигурации репорта (docs/REPORTS.md §4).
 *
 * Возвращает список ошибок вида "путь: сообщение" (пустой список = валидно).
 * Эти же тексты скармливаются ИИ при повторной попытке генерации, поэтому
 * они должны быть самодостаточными.
 */
class ReportValidator
{
    /** @return list<string> */
    public function validate(mixed $config): array
    {
        if (!\is_array($config)) {
            return ['config: должен быть JSON-объектом'];
        }

        $errors = [];
        if ('' === trim((string) ($config['name'] ?? ''))) {
            $errors[] = 'name: обязательное непустое поле';
        }

        $widgets = $config['widgets'] ?? null;
        if (!\is_array($widgets) || 0 === \count($widgets)) {
            $errors[] = 'widgets: обязателен непустой массив';

            return $errors;
        }
        if (\count($widgets) > ReportSchema::MAX_WIDGETS) {
            $errors[] = 'widgets: не более '.ReportSchema::MAX_WIDGETS;
        }

        foreach (array_values($widgets) as $i => $widget) {
            $path = "widgets[{$i}]";
            if (!\is_array($widget)) {
                $errors[] = "{$path}: должен быть объектом";
                continue;
            }
            if ('' === trim((string) ($widget['title'] ?? ''))) {
                $errors[] = "{$path}.title: обязательное непустое поле";
            }
            $width = $widget['width'] ?? 1;
            if (!\in_array($width, [1, 2, 3], true)) {
                $errors[] = "{$path}.width: допустимо 1, 2 или 3";
            }
            $vizType = $widget['viz']['type'] ?? null;
            if (!\is_string($vizType) || !isset(ReportSchema::VIZ[$vizType])) {
                $errors[] = "{$path}.viz.type: допустимо ".implode(', ', array_keys(ReportSchema::VIZ));
                $vizType = null;
            }
            if (!\is_array($widget['query'] ?? null)) {
                $errors[] = "{$path}.query: обязательный объект";
                continue;
            }
            array_push($errors, ...$this->validateQuery($widget['query'], $vizType, "{$path}.query"));
        }

        return $errors;
    }

    /**
     * Валидация одного query (+совместимость с типом визуализации).
     * Используется и целым конфигом, и /api/report-data.
     *
     * @return list<string>
     */
    public function validateQuery(mixed $query, ?string $vizType, string $path = 'query'): array
    {
        if (!\is_array($query)) {
            return ["{$path}: должен быть объектом"];
        }
        $errors = [];

        // time_range
        $tr = $query['time_range'] ?? null;
        if (!\is_array($tr)) {
            $errors[] = "{$path}.time_range: обязателен ({\"last_hours\": N} или {\"from\": ..., \"to\": ...})";
        } elseif (isset($tr['last_hours'])) {
            if (!\is_int($tr['last_hours']) || $tr['last_hours'] < 1 || $tr['last_hours'] > ReportSchema::MAX_LAST_HOURS) {
                $errors[] = "{$path}.time_range.last_hours: целое 1..".ReportSchema::MAX_LAST_HOURS;
            }
        } elseif (isset($tr['from'], $tr['to'])) {
            foreach (['from', 'to'] as $k) {
                if (null === $this->parseDateTime((string) $tr[$k])) {
                    $errors[] = "{$path}.time_range.{$k}: дата в формате YYYY-MM-DD HH:MM[:SS]";
                }
            }
        } else {
            $errors[] = "{$path}.time_range: нужен last_hours либо from+to";
        }

        // filters
        $filters = $query['filters'] ?? [];
        if (!\is_array($filters)) {
            $errors[] = "{$path}.filters: должен быть массивом";
        } else {
            if (\count($filters) > ReportSchema::MAX_FILTERS) {
                $errors[] = "{$path}.filters: не более ".ReportSchema::MAX_FILTERS;
            }
            foreach (array_values($filters) as $i => $filter) {
                array_push($errors, ...$this->validateFilter($filter, "{$path}.filters[{$i}]"));
            }
        }

        // group_by
        $groupBy = $query['group_by'] ?? [];
        if (!\is_array($groupBy) || \count($groupBy) > 2) {
            $errors[] = "{$path}.group_by: массив из 0..2 измерений";
            $groupBy = [];
        }
        foreach ($groupBy as $field) {
            if (!\is_string($field) || !Schema::isDimension($field)) {
                $errors[] = "{$path}.group_by: неизвестное измерение \"".$this->str($field).'"; допустимы: '.implode(', ', Schema::DIMENSIONS);
            }
        }

        // time_bucket
        $timeBucket = $query['time_bucket'] ?? null;
        if (null !== $timeBucket && !\in_array($timeBucket, ReportSchema::TIME_BUCKETS, true)) {
            $errors[] = "{$path}.time_bucket: null либо ".implode(', ', ReportSchema::TIME_BUCKETS);
            $timeBucket = null;
        }

        // aggregations
        $aggs = $query['aggregations'] ?? null;
        $aliases = [];
        if (!\is_array($aggs) || 0 === \count($aggs)) {
            $errors[] = "{$path}.aggregations: обязателен непустой массив";
            $aggs = [];
        } elseif (\count($aggs) > ReportSchema::MAX_AGGREGATIONS) {
            $errors[] = "{$path}.aggregations: не более ".ReportSchema::MAX_AGGREGATIONS;
        }
        foreach (array_values($aggs) as $i => $agg) {
            $p = "{$path}.aggregations[{$i}]";
            if (!\is_array($agg)) {
                $errors[] = "{$p}: должен быть объектом";
                continue;
            }
            $fn = $agg['fn'] ?? null;
            $spec = \is_string($fn) ? (ReportSchema::AGGREGATIONS[$fn] ?? null) : null;
            if (null === $spec) {
                $errors[] = "{$p}.fn: допустимо ".implode(', ', array_keys(ReportSchema::AGGREGATIONS));
            } else {
                $field = $agg['field'] ?? null;
                switch ($spec['field']) {
                    case 'metric':
                        if (!\is_string($field) || !Schema::isMetric($field)) {
                            $errors[] = "{$p}.field: для {$fn} нужна метрика из: ".implode(', ', Schema::METRICS);
                        }
                        break;
                    case 'dimension':
                        if (!\is_string($field) || !Schema::isDimension($field)) {
                            $errors[] = "{$p}.field: для {$fn} нужно измерение из: ".implode(', ', Schema::DIMENSIONS);
                        }
                        break;
                    case 'none':
                        if (null !== $field) {
                            $errors[] = "{$p}.field: для {$fn} поле не указывается";
                        }
                        break;
                }
                if ($spec['filters'] ?? false) {
                    foreach (array_values($agg['filters'] ?? []) as $j => $filter) {
                        array_push($errors, ...$this->validateFilter($filter, "{$p}.filters[{$j}]"));
                    }
                }
            }
            $alias = $agg['alias'] ?? null;
            if (!\is_string($alias) || !preg_match(ReportSchema::ALIAS_PATTERN, $alias)
                || \in_array($alias, ['t', 'g0', 'g1'], true)) {
                $errors[] = "{$p}.alias: обязателен, формат ^[a-z][a-z0-9_]{0,30}$ (t, g0, g1 зарезервированы)";
            } elseif (\in_array($alias, $aliases, true)) {
                $errors[] = "{$p}.alias: \"{$alias}\" уже используется";
            } else {
                $aliases[] = $alias;
            }
        }

        // sort
        if (isset($query['sort'])) {
            $sort = $query['sort'];
            if (!\is_array($sort) || !\in_array($sort['dir'] ?? 'desc', ['asc', 'desc'], true)) {
                $errors[] = "{$path}.sort: {\"by\": alias, \"dir\": \"asc\"|\"desc\"}";
            } elseif (!\in_array($sort['by'] ?? null, $aliases, true)) {
                $errors[] = "{$path}.sort.by: должен быть alias одной из агрегаций";
            }
        }

        // limit / флаги / sample
        if (isset($query['limit']) && (!\is_int($query['limit']) || $query['limit'] < 1 || $query['limit'] > ReportSchema::MAX_LIMIT)) {
            $errors[] = "{$path}.limit: целое 1..".ReportSchema::MAX_LIMIT;
        }
        foreach (['top_n_other', 'compare_previous_period'] as $flag) {
            if (isset($query[$flag]) && !\is_bool($query[$flag])) {
                $errors[] = "{$path}.{$flag}: булево";
            }
        }
        if (isset($query['sample']) && null !== $query['sample']
            && !\in_array($query['sample'], ReportSchema::SAMPLES, true)) {
            $errors[] = "{$path}.sample: null либо ".implode(', ', array_map(strval(...), ReportSchema::SAMPLES));
        }

        // совместимость с визуализацией
        if (null !== $vizType) {
            $rules = ReportSchema::VIZ[$vizType];
            if (true === $rules['time_bucket'] && null === $timeBucket) {
                $errors[] = "{$path}.time_bucket: обязателен для viz \"{$vizType}\"";
            }
            if (false === $rules['time_bucket'] && null !== $timeBucket) {
                $errors[] = "{$path}.time_bucket: недопустим для viz \"{$vizType}\"";
            }
            [$min, $max] = $rules['group_by'];
            $n = \count($groupBy);
            if ($n < $min || $n > $max) {
                $errors[] = "{$path}.group_by: для viz \"{$vizType}\" нужно от {$min} до {$max} измерений";
            }
            if (null !== $rules['max_aggregations'] && \count($aggs) > $rules['max_aggregations']) {
                $errors[] = "{$path}.aggregations: для viz \"{$vizType}\" не более {$rules['max_aggregations']}";
            }
            if (($query['compare_previous_period'] ?? false) && 'stat' !== $vizType) {
                $errors[] = "{$path}.compare_previous_period: только для viz \"stat\"";
            }
            if (($query['top_n_other'] ?? false) && 1 !== \count($groupBy)) {
                $errors[] = "{$path}.top_n_other: требует ровно одно измерение в group_by";
            }
        }

        return $errors;
    }

    /**
     * Валидация одного фильтра — используется также узлом filter алертов.
     *
     * @return list<string>
     */
    public function validateFilter(mixed $filter, string $path): array
    {
        if (!\is_array($filter)) {
            return ["{$path}: должен быть объектом {field, op, value}"];
        }
        $field = $filter['field'] ?? null;
        $op = $filter['op'] ?? null;
        $value = $filter['value'] ?? null;

        if (\is_string($field) && Schema::isDimension($field)) {
            if (!\in_array($op, ReportSchema::DIMENSION_OPS, true)) {
                return ["{$path}.op: для измерения допустимо ".implode(', ', ReportSchema::DIMENSION_OPS)];
            }
            if (\in_array($op, ['in', 'not_in'], true)) {
                if (!\is_array($value) || [] === $value || $value !== array_filter($value, is_string(...))) {
                    return ["{$path}.value: для {$op} нужен непустой массив строк"];
                }
            } elseif (!\is_string($value)) {
                return ["{$path}.value: строка"];
            }

            return [];
        }

        if (\is_string($field) && Schema::isMetric($field)) {
            if (!\in_array($op, ReportSchema::METRIC_OPS, true)) {
                return ["{$path}.op: для метрики допустимо ".implode(', ', ReportSchema::METRIC_OPS)];
            }
            if ('between' === $op) {
                if (!\is_array($value) || 2 !== \count($value) || $value !== array_filter($value, is_numeric(...))) {
                    return ["{$path}.value: для between нужен массив [min, max]"];
                }
            } elseif (!is_numeric($value)) {
                return ["{$path}.value: число"];
            }

            return [];
        }

        return ["{$path}.field: неизвестное поле \"".$this->str($field).'"'];
    }

    public function parseDateTime(string $value): ?string
    {
        $value = str_replace('T', ' ', trim($value));
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $dt) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    private function str(mixed $v): string
    {
        return \is_scalar($v) ? (string) $v : gettype($v);
    }
}
