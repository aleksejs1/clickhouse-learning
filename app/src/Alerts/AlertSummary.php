<?php

namespace App\Alerts;

/**
 * Человекочитаемый пересказ графа алерта (docs/ALERTS.md §5): обход от каждого
 * триггера к действиям, включая обе ветки condition. Показывается пользователю,
 * в списке алертов и как обратная связь ИИ.
 *
 * Вход может быть невалидным (сборка не должна падать) — при кривом графе
 * возвращает то, что удалось разобрать.
 */
class AlertSummary
{
    public function build(array $config): string
    {
        $nodes = $config['nodes'] ?? [];
        $edges = $config['edges'] ?? [];
        if (!\is_array($nodes) || !\is_array($edges) || 0 === \count($nodes)) {
            return '';
        }

        $byId = [];
        foreach ($nodes as $node) {
            if (\is_array($node) && isset($node['id'])) {
                $byId[$node['id']] = $node;
            }
        }
        $adj = [];
        foreach ($edges as $edge) {
            $from = $edge['from'] ?? null;
            if (isset($byId[$from])) {
                $adj[$from][] = ['to' => $edge['to'] ?? null, 'port' => $edge['from_port'] ?? null];
            }
        }

        $lines = [];
        foreach ($byId as $id => $node) {
            $spec = NodeCatalog::get($node['type'] ?? '');
            if ($spec && 'trigger' === $spec['category']) {
                foreach ($this->walk($id, $byId, $adj, []) as $path) {
                    $lines[] = $path;
                }
            }
        }

        return implode("; \n", array_unique($lines));
    }

    /**
     * Возвращает список текстовых путей от узла $id.
     *
     * @return list<string>
     */
    private function walk(string $id, array $byId, array $adj, array $visited): array
    {
        if (isset($visited[$id])) {
            return ['…цикл'];
        }
        $visited[$id] = true;
        $phrase = $this->describe($byId[$id] ?? []);
        $next = $adj[$id] ?? [];

        if (0 === \count($next)) {
            return [$phrase];
        }

        $result = [];
        foreach ($next as $e) {
            $to = $e['to'];
            if (!isset($byId[$to])) {
                continue;
            }
            $portLabel = null !== $e['port'] ? ('true' === $e['port'] ? '[да] ' : '[нет] ') : '';
            foreach ($this->walk($to, $byId, $adj, $visited) as $tail) {
                $result[] = $phrase.' → '.$portLabel.$tail;
            }
        }

        return $result ?: [$phrase];
    }

    private function describe(array $node): string
    {
        $p = $node['params'] ?? [];
        $g = fn (string $k, $d = '') => $p[$k] ?? $d;

        return match ($node['type'] ?? '') {
            'metric_threshold' => sprintf('%s(%s) по %s за %s мин %s %s',
                $g('agg', 'avg'), $g('metric', '?'), $g('group_by', 'vehicle_id'),
                $g('window_minutes', 15), $g('op', '>'), $g('value', '?')),
            'anomaly_deviation' => sprintf('отклонение %s по %s > %sσ за %sч',
                $g('metric', '?'), $g('dimension', '?'), $g('sigma_threshold', 3), $g('window_hours', 24)),
            'fuel_drop' => sprintf('падение топлива > %s%% за %s мин', $g('drop_pct', 15), $g('window_minutes', 10)),
            'no_data' => sprintf('нет данных по %s более %s мин', $g('group_by', 'vehicle_id'), $g('silence_minutes', 30)),
            'geofence' => sprintf('%s геозоны (%s, %s) R=%sкм', 'exit' === $g('direction', 'exit') ? 'выход из' : 'вход в', $g('lat', '?'), $g('lon', '?'), $g('radius_km', 5)),
            'odometer_milestone' => sprintf('каждые %s км пробега', $g('every_km', 15000)),
            'schedule' => sprintf('по расписанию (%s)', $g('cron', '?')),
            'filter' => 'фильтр',
            'condition' => sprintf('если %s %s %s', $g('field', '?'), $g('op', '>'), $g('value', '?')),
            'time_window' => sprintf('в окне %s–%s', $g('from_time', '00:00'), $g('to_time', '23:59')),
            'dedup' => sprintf('не чаще раза в %s мин', $g('cooldown_minutes', 60)),
            'severity' => sprintf('важность %s', $g('level', 'warning')),
            'digest' => sprintf('дайджест раз в %s мин', $g('period_minutes', 60)),
            'notify_email' => sprintf('письмо на %s', $g('to', '?')),
            'notify_sms' => sprintf('SMS на %s', $g('phone', '?')),
            'notify_telegram' => sprintf('Telegram %s', $g('chat_id', '?')),
            'webhook' => sprintf('webhook %s', $g('url', '?')),
            'create_ticket' => sprintf('тикет в %s', $g('queue', '?')),
            'escalate' => sprintf('эскалация через %s мин на %s', $g('after_minutes', 60), $g('to', '?')),
            default => $node['type'] ?? '?',
        };
    }
}
