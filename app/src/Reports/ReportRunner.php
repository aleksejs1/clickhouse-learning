<?php

namespace App\Reports;

use App\Schema;
use App\Service\ClickHouse;

/**
 * Выполняет query-конфиг одного виджета (docs/REPORTS.md §5).
 *
 * Вход считается ПРОВАЛИДИРОВАННЫМ (ReportValidator): имена полей и функций
 * здесь уже из белых списков, значения фильтров уходят серверными параметрами.
 * Внутренние имена колонок: t — время, g0/g1 — группировки (алиасы агрегаций
 * с ними не пересекаются — валидатор резервирует).
 */
class ReportRunner
{
    public function __construct(private ClickHouse $clickHouse)
    {
    }

    /** @return array{viz_type: string, labels: list, series: list, columns: list, rows: list, meta: array} */
    public function run(array $query, string $vizType): array
    {
        [$from, $to] = $this->timeRange($query['time_range']);
        $rows = $this->execute($query, $from, $to);
        $result = $this->format($rows, $query, $vizType);

        if (($query['compare_previous_period'] ?? false) && 'stat' === $vizType) {
            $len = strtotime($to) - strtotime($from);
            $prevRows = $this->execute($query, date('Y-m-d H:i:s', strtotime($from) - $len), $from);
            $alias = $query['aggregations'][0]['alias'];
            $current = (float) ($rows[0][$alias] ?? 0);
            $previous = (float) ($prevRows[0][$alias] ?? 0);
            $result['meta']['previous'] = round($previous, 2);
            $result['meta']['delta_pct'] = abs($previous) > 1e-9
                ? round(($current - $previous) / $previous * 100, 1)
                : null;
        }

        $result['meta']['queries'] = $this->clickHouse->queryLog();

        return $result;
    }

    /** @return array{string, string} [from, to] в формате CH DateTime */
    private function timeRange(array $tr): array
    {
        if (isset($tr['last_hours'])) {
            return [date('Y-m-d H:i:s', time() - 3600 * (int) $tr['last_hours']), date('Y-m-d H:i:s')];
        }
        $parse = fn (string $v): string => (new ReportValidator())->parseDateTime($v)
            ?? throw new \InvalidArgumentException('bad datetime');

        return [$parse((string) $tr['from']), $parse((string) $tr['to'])];
    }

    /** @return list<array<string, mixed>> */
    private function execute(array $query, string $from, string $to): array
    {
        $params = [];
        $n = 0;
        $param = function (mixed $v, string $t) use (&$params, &$n): string {
            $name = 'p'.$n++;
            $params[$name] = $v;

            return '{'.$name.':'.$t.'}';
        };

        $where = [
            'event_time >= '.$param($from, 'DateTime'),
            'event_time <= '.$param($to, 'DateTime'),
        ];
        foreach ($query['filters'] ?? [] as $f) {
            $where[] = $this->filterSql($f, $param);
        }

        $sample = $query['sample'] ?? null;
        $fromSql = 'events'.($sample ? ' SAMPLE '.$sample : ''); // значение из белого списка
        $scale = $sample ? 1 / (float) $sample : 1.0;

        $select = [];
        $group = [];
        if (null !== ($tb = $query['time_bucket'] ?? null)) {
            $expr = match ($tb) {
                'hour' => 'toStartOfHour(event_time)',
                'day' => 'toStartOfDay(event_time)',
                'week' => 'toStartOfWeek(event_time)',
                'month' => 'toStartOfMonth(event_time)',
            };
            $select[] = "toString({$expr}) AS t";
            $group[] = 't';
        }

        $groupBy = array_values($query['group_by'] ?? []);
        $topValues = ($query['top_n_other'] ?? false)
            ? $this->topValues($query, $from, $to)
            : null;
        foreach ($groupBy as $i => $g) {
            if (null !== $topValues && 0 === $i) {
                $list = implode(', ', array_map(fn ($v) => $param($v, 'String'), $topValues));
                $select[] = '' !== $list
                    ? "if({$g} IN ({$list}), {$g}, 'other') AS g{$i}"
                    : "'other' AS g{$i}";
            } else {
                $select[] = "{$g} AS g{$i}";
            }
            $group[] = "g{$i}";
        }

        $scaled = [];
        foreach ($query['aggregations'] as $agg) {
            $select[] = $this->aggSql($agg, $param).' AS '.$agg['alias'];
            if ($scale > 1 && (ReportSchema::AGGREGATIONS[$agg['fn']]['scalable'] ?? false)) {
                $scaled[] = $agg['alias'];
            }
        }

        $sql = 'SELECT '.implode(', ', $select)." FROM {$fromSql} WHERE ".implode(' AND ', $where);
        if ($group) {
            $sql .= ' GROUP BY '.implode(', ', $group);
        }

        if (\in_array('t', $group, true)) {
            // хронология важнее сортировки по значению
            $sql .= ' ORDER BY '.implode(', ', $group).' LIMIT 10000';
        } elseif ($group) {
            $sort = $query['sort'] ?? null;
            $by = $sort['by'] ?? $query['aggregations'][0]['alias']; // alias проверен валидатором
            $dir = 'asc' === ($sort['dir'] ?? 'desc') ? 'ASC' : 'DESC';
            $limit = null !== $topValues ? 10000 : (int) ($query['limit'] ?? 100);
            $sql .= " ORDER BY {$by} {$dir} LIMIT {$limit}";
        }

        $rows = $this->clickHouse->select($sql, $params);

        // числа: CH отдаёт UInt64 строками; заодно домножаем счётные при сэмпле
        foreach ($rows as &$row) {
            foreach ($query['aggregations'] as $agg) {
                $a = $agg['alias'];
                $row[$a] = round((float) $row[$a] * (\in_array($a, $scaled, true) ? $scale : 1), 2);
            }
        }

        return $rows;
    }

    /** Топ-значения первой группировки для top_n_other. @return list<string> */
    private function topValues(array $query, string $from, string $to): array
    {
        $params = [];
        $n = 0;
        $param = function (mixed $v, string $t) use (&$params, &$n): string {
            $name = 'p'.$n++;
            $params[$name] = $v;

            return '{'.$name.':'.$t.'}';
        };

        $where = [
            'event_time >= '.$param($from, 'DateTime'),
            'event_time <= '.$param($to, 'DateTime'),
        ];
        foreach ($query['filters'] ?? [] as $f) {
            $where[] = $this->filterSql($f, $param);
        }

        $g = $query['group_by'][0];
        $sort = $query['sort'] ?? null;
        $sortAlias = $sort['by'] ?? $query['aggregations'][0]['alias'];
        $agg = null;
        foreach ($query['aggregations'] as $a) {
            if ($a['alias'] === $sortAlias) {
                $agg = $a;
            }
        }
        $dir = 'asc' === ($sort['dir'] ?? 'desc') ? 'ASC' : 'DESC';
        $limit = (int) ($query['limit'] ?? 10);
        $sample = ($query['sample'] ?? null) ? ' SAMPLE '.$query['sample'] : '';

        $rows = $this->clickHouse->select(
            "SELECT {$g} AS v FROM events{$sample} WHERE ".implode(' AND ', $where)
            ." GROUP BY v ORDER BY {$this->aggSql($agg, $param)} {$dir} LIMIT {$limit}",
            $params,
        );

        return array_map(static fn ($r) => (string) $r['v'], $rows);
    }

    private function aggSql(array $agg, callable $param): string
    {
        $f = $agg['field'] ?? null; // имя поля проверено валидатором

        return match ($agg['fn']) {
            'count' => 'count()',
            'uniq' => "uniq({$f})",
            'sum' => "sum({$f})",
            'avg' => "avg({$f})",
            'min' => "min({$f})",
            'max' => "max({$f})",
            'median' => "quantile(0.5)({$f})",
            'p95' => "quantile(0.95)({$f})",
            'max_minus_min' => "max({$f}) - min({$f})",
            'count_if' => ($conds = array_map(fn ($flt) => $this->filterSql($flt, $param), $agg['filters'] ?? []))
                ? 'countIf('.implode(' AND ', $conds).')'
                : 'count()',
        };
    }

    private function filterSql(array $f, callable $param): string
    {
        $field = $f['field'];
        $op = $f['op'];
        $v = $f['value'];

        if (Schema::isDimension($field)) {
            return match ($op) {
                '=' => "{$field} = ".$param($v, 'String'),
                '!=' => "{$field} != ".$param($v, 'String'),
                'in' => "{$field} IN (".implode(', ', array_map(fn ($x) => $param($x, 'String'), $v)).')',
                'not_in' => "{$field} NOT IN (".implode(', ', array_map(fn ($x) => $param($x, 'String'), $v)).')',
            };
        }

        return match ($op) {
            'between' => "{$field} BETWEEN ".$param((float) $v[0], 'Float64').' AND '.$param((float) $v[1], 'Float64'),
            default => "{$field} {$op} ".$param((float) $v, 'Float64'), // op из METRIC_OPS
        };
    }

    /** Раскладка строк в форму для Chart.js (см. docs/REPORTS.md §6). */
    private function format(array $rows, array $query, string $vizType): array
    {
        $groupBy = array_values($query['group_by'] ?? []);
        $hasTime = null !== ($query['time_bucket'] ?? null);
        $aliases = array_column($query['aggregations'], 'alias');

        $columns = [];
        if ($hasTime) {
            $columns[] = ['key' => 't', 'label' => 'time'];
        }
        foreach ($groupBy as $i => $g) {
            $columns[] = ['key' => "g{$i}", 'label' => $g];
        }
        foreach ($aliases as $a) {
            $columns[] = ['key' => $a, 'label' => $a];
        }

        $labels = [];
        $series = [];
        if ($hasTime && $groupBy) {
            // серия на группу, данные первой агрегации, выровнены по меткам времени
            $labels = array_values(array_unique(array_column($rows, 't')));
            $byGroup = [];
            foreach ($rows as $row) {
                $byGroup[$row['g0']][$row['t']] = $row[$aliases[0]];
            }
            foreach ($byGroup as $g => $points) {
                $series[] = [
                    'name' => (string) $g,
                    'data' => array_map(static fn ($t) => $points[$t] ?? 0, $labels),
                ];
            }
        } elseif ($hasTime) {
            $labels = array_column($rows, 't');
            foreach ($aliases as $a) {
                $series[] = ['name' => $a, 'data' => array_column($rows, $a)];
            }
        } elseif ($groupBy) {
            $labels = array_map(static fn ($r) => (string) $r['g0'], $rows);
            foreach ($aliases as $a) {
                $series[] = ['name' => $a, 'data' => array_column($rows, $a)];
            }
        } else {
            foreach ($aliases as $a) {
                $series[] = ['name' => $a, 'data' => [$rows[0][$a] ?? 0]];
            }
        }

        return [
            'viz_type' => $vizType,
            'labels' => $labels,
            'series' => $series,
            'columns' => $columns,
            'rows' => \array_slice($rows, 0, 1000),
            'meta' => [],
        ];
    }
}
