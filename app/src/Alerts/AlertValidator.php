<?php

namespace App\Alerts;

use App\Reports\ReportValidator;

/**
 * Валидация графа алерта (docs/ALERTS.md §5): параметры узлов по каталогу +
 * структура графа (триггеры, пути к действиям, ацикличность, порты).
 *
 * Возвращает список ошибок вида {node, message} (пусто = валиден); тексты
 * самодостаточны — идут и в UI, и в ИИ-петлю.
 */
class AlertValidator
{
    public function __construct(private ReportValidator $reportValidator)
    {
    }

    private const ID_PATTERN = '/^[a-z][a-z0-9_]{0,20}$/';

    /** @return list<array{node: string, message: string}> */
    public function validate(mixed $config): array
    {
        if (!\is_array($config)) {
            return [['node' => '', 'message' => 'config: должен быть JSON-объектом']];
        }
        $errors = [];
        if ('' === trim((string) ($config['name'] ?? ''))) {
            $errors[] = ['node' => '', 'message' => 'name: обязательное непустое поле'];
        }

        $nodes = $config['nodes'] ?? null;
        $edges = $config['edges'] ?? [];
        if (!\is_array($nodes) || 0 === \count($nodes)) {
            $errors[] = ['node' => '', 'message' => 'nodes: обязателен непустой массив'];

            return $errors;
        }
        if (!\is_array($edges)) {
            $errors[] = ['node' => '', 'message' => 'edges: должен быть массивом'];
            $edges = [];
        }

        // узлы: id, тип, параметры
        $byId = [];
        foreach ($nodes as $node) {
            $id = \is_array($node) ? ($node['id'] ?? null) : null;
            if (!\is_string($id) || !preg_match(self::ID_PATTERN, $id)) {
                $errors[] = ['node' => (string) $id, 'message' => 'id: формат ^[a-z][a-z0-9_]{0,20}$'];
                continue;
            }
            if (isset($byId[$id])) {
                $errors[] = ['node' => $id, 'message' => 'id: дублируется'];
                continue;
            }
            $byId[$id] = $node;
            array_push($errors, ...$this->validateNode($node));
        }

        // рёбра ссылаются на существующие узлы; from_port только у 2-выходных
        $outDeg = array_fill_keys(array_keys($byId), 0);
        $inDeg = array_fill_keys(array_keys($byId), 0);
        $adj = array_fill_keys(array_keys($byId), []);
        foreach ($edges as $i => $edge) {
            $from = $edge['from'] ?? null;
            $to = $edge['to'] ?? null;
            if (!isset($byId[$from]) || !isset($byId[$to])) {
                $errors[] = ['node' => '', 'message' => "edges[{$i}]: ссылка на несуществующий узел"];
                continue;
            }
            $spec = NodeCatalog::get($byId[$from]['type'] ?? '');
            if ($spec && ($spec['outputs'] ?? 0) >= 2) {
                $ports = $spec['ports'] ?? [];
                if (!\in_array($edge['from_port'] ?? null, $ports, true)) {
                    $errors[] = ['node' => $from, 'message' => 'edges: у узла с ветвлением нужен from_port ('.implode('/', $ports).')'];
                }
            } elseif (isset($edge['from_port'])) {
                $errors[] = ['node' => $from, 'message' => 'edges: from_port только у узлов с двумя выходами'];
            }
            ++$outDeg[$from];
            ++$inDeg[$to];
            $adj[$from][] = $to;
        }

        // структурные правила по категориям
        $triggers = [];
        foreach ($byId as $id => $node) {
            $spec = NodeCatalog::get($node['type'] ?? '');
            if (null === $spec) {
                continue;
            }
            $cat = $spec['category'];
            if ('trigger' === $cat) {
                $triggers[] = $id;
                if ($inDeg[$id] > 0) {
                    $errors[] = ['node' => $id, 'message' => 'триггер не может иметь входящих рёбер'];
                }
            }
            if ('action' === $cat && $outDeg[$id] > 0) {
                $errors[] = ['node' => $id, 'message' => 'действие не может иметь исходящих рёбер'];
            }
            if ('condition' === $cat && 0 === $inDeg[$id]) {
                $errors[] = ['node' => $id, 'message' => 'узел обработки без входящих рёбер (сирота)'];
            }
        }
        if (0 === \count($triggers)) {
            $errors[] = ['node' => '', 'message' => 'нужен хотя бы один триггер'];
        }

        // цикл
        if ($this->hasCycle($adj)) {
            $errors[] = ['node' => '', 'message' => 'граф содержит цикл'];
        }

        // каждый триггер достигает хотя бы одного действия
        foreach ($triggers as $t) {
            if (!$this->reachesAction($t, $adj, $byId)) {
                $errors[] = ['node' => $t, 'message' => 'от триггера нет пути к действию'];
            }
        }

        return $errors;
    }

    /** @return list<array{node: string, message: string}> */
    private function validateNode(array $node): array
    {
        $id = $node['id'];
        $type = $node['type'] ?? null;
        $spec = \is_string($type) ? NodeCatalog::get($type) : null;
        if (null === $spec) {
            return [['node' => $id, 'message' => "неизвестный тип узла \"".$this->str($type).'"']];
        }

        $errors = [];
        $params = $node['params'] ?? [];
        if (!\is_array($params)) {
            return [['node' => $id, 'message' => 'params: должен быть объектом']];
        }
        foreach ($spec['params'] as $p) {
            $name = $p['name'];
            $val = $params[$name] ?? null;
            if (null === $val) {
                if ($p['required'] ?? false) {
                    $errors[] = ['node' => $id, 'message' => "params.{$name}: обязательный параметр"];
                }
                continue;
            }
            $err = $this->validateParam($p, $val);
            if (null !== $err) {
                $errors[] = ['node' => $id, 'message' => "params.{$name}: {$err}"];
            }
        }

        return $errors;
    }

    private function validateParam(array $p, mixed $val): ?string
    {
        switch ($p['type']) {
            case 'number':
                return is_numeric($val) ? null : 'должно быть числом';
            case 'bool':
                return \is_bool($val) ? null : 'должно быть булевым';
            case 'string':
            case 'template':
            case 'cron':
                return \is_string($val) ? null : 'должно быть строкой';
            case 'enum':
                return \in_array($val, $p['values'], true) ? null : 'допустимо: '.implode(', ', $p['values']);
            case 'enum_multi':
                if (!\is_array($val) || $val !== array_values(array_filter($val, fn ($v) => \in_array($v, $p['values'], true)))) {
                    return 'массив из: '.implode(', ', $p['values']);
                }

                return null;
            case 'dimension_field':
            case 'metric_field':
                return \in_array($val, $p['values'], true) ? null : 'поле из: '.implode(', ', $p['values']);
            case 'filters':
                if (!\is_array($val)) {
                    return 'массив фильтров';
                }
                foreach (array_values($val) as $i => $f) {
                    $fe = $this->reportValidator->validateFilter($f, "[{$i}]");
                    if ($fe) {
                        return $fe[0];
                    }
                }

                return null;
            default:
                return null;
        }
    }

    /** @param array<string, list<string>> $adj */
    private function hasCycle(array $adj): bool
    {
        $state = []; // 1 = в обработке, 2 = завершён
        $visit = function (string $n) use (&$visit, &$state, $adj): bool {
            $state[$n] = 1;
            foreach ($adj[$n] ?? [] as $next) {
                $s = $state[$next] ?? 0;
                if (1 === $s || (0 === $s && $visit($next))) {
                    return true;
                }
            }
            $state[$n] = 2;

            return false;
        };
        foreach (array_keys($adj) as $n) {
            if (0 === ($state[$n] ?? 0) && $visit($n)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, list<string>> $adj */
    private function reachesAction(string $start, array $adj, array $byId): bool
    {
        $seen = [];
        $stack = [$start];
        while ($stack) {
            $n = array_pop($stack);
            if (isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            $spec = NodeCatalog::get($byId[$n]['type'] ?? '');
            if ($spec && 'action' === $spec['category']) {
                return true;
            }
            foreach ($adj[$n] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return false;
    }

    private function str(mixed $v): string
    {
        return \is_scalar($v) ? (string) $v : gettype($v);
    }
}
