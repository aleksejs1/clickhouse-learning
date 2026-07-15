<?php

namespace App\Ai;

use App\Alerts\NodeCatalog;
use App\Reports\ReportSchema;
use App\Schema;

/**
 * Сборка system prompt в СТАБИЛЬНОМ порядке (docs/AI_ASSISTANT.md §6):
 * статичная часть → словарь данных → формат конфига → few-shot примеры.
 * Порядок неизменен, чтобы работал промпт-кэш.
 */
class PromptBuilder
{
    // значения измерений (из генератора, docs/DATA_MODEL.md §2) — помогают ИИ
    // подставлять реальные фильтры вроде event_type = 'refuel'
    private const DIMENSION_VALUES = [
        'event_type' => 'gps_ping, trip_start, trip_end, refuel, harsh_braking, speeding, idle, engine_fault',
        'vehicle_id' => 'V-001…V-050',
        'vehicle_type' => 'truck, van, refrigerated_truck, tanker',
        'vehicle_make' => 'volvo, scania, man, mercedes, daf',
        'driver_id' => 'D-01…D-30',
        'route_id' => 'R-01…R-15',
        'region' => 'riga, kurzeme, latgale, vidzeme, zemgale',
        'road_type' => 'highway, city, rural',
        'weather' => 'clear, rain, snow, fog',
        'last_fuel_station_id' => 'FS-01…FS-10 (последняя заправка машины)',
    ];

    private const METRIC_HINTS = [
        'speed_kmh' => 'скорость, км/ч',
        'fuel_level_pct' => 'уровень топлива, %',
        'fuel_consumption_l100' => 'расход, л/100км',
        'engine_temp_c' => 'температура двигателя, °C',
        'engine_rpm' => 'обороты двигателя',
        'cargo_weight_kg' => 'вес груза, кг',
        'odometer_km' => 'одометр, км',
        'ambient_temp_c' => 'температура за бортом, °C',
        'tire_pressure_bar' => 'давление в шинах, бар',
        'trip_duration_min' => 'длительность рейса, мин',
        'harsh_events_cnt' => 'счётчик резких манёвров',
    ];

    public function __construct(private string $promptsDir, private string $examplesDir)
    {
    }

    public function report(): string
    {
        return implode("\n\n", [
            $this->staticPart('report.md'),
            $this->dataDictionary(),
            $this->reportFormat(),
            $this->examples('reports', 2),
        ]);
    }

    public function alert(): string
    {
        return implode("\n\n", [
            $this->staticPart('alert.md'),
            $this->dataDictionary(),
            $this->alertFormat(),
            $this->examples('alerts', 2),
        ]);
    }

    private function staticPart(string $file): string
    {
        return trim(file_get_contents($this->promptsDir.'/'.$file));
    }

    private function dataDictionary(): string
    {
        $dims = [];
        foreach (Schema::DIMENSIONS as $d) {
            $dims[] = "- {$d}: ".(self::DIMENSION_VALUES[$d] ?? '');
        }
        $metrics = [];
        foreach (Schema::METRICS as $m) {
            $metrics[] = "- {$m}: ".(self::METRIC_HINTS[$m] ?? '');
        }

        return "# СЛОВАРЬ ДАННЫХ (таблица events)\n\n"
            ."Измерения (категориальные, для group_by/filter/group):\n".implode("\n", $dims)
            ."\n\nМетрики (числовые, для агрегаций):\n".implode("\n", $metrics);
    }

    private function reportFormat(): string
    {
        $viz = [];
        foreach (ReportSchema::VIZ as $type => $rules) {
            $tb = true === $rules['time_bucket'] ? 'нужен time_bucket'
                : (false === $rules['time_bucket'] ? 'без time_bucket' : 'любой');
            [$min, $max] = $rules['group_by'];
            $viz[] = "- {$type}: {$tb}, group_by {$min}..{$max}"
                .(null !== $rules['max_aggregations'] ? ", максимум {$rules['max_aggregations']} агрегаций" : '');
        }
        $fns = [];
        foreach (ReportSchema::AGGREGATIONS as $fn => $spec) {
            $need = 'metric' === $spec['field'] ? '(нужна метрика)' : ('dimension' === $spec['field'] ? '(нужно измерение)' : '(без поля)');
            $fns[] = "{$fn} {$need}";
        }

        return "# ФОРМАТ КОНФИГА РЕПОРТА\n\n"
            ."config = {version, name, description, widgets: [...]}\n"
            ."widget = {title, width: 1..3, query, viz: {type}}\n"
            ."query = {time_range, filters, group_by, time_bucket, aggregations, sort, limit, sample, top_n_other, compare_previous_period}\n"
            ."time_range = {\"last_hours\": N} или {\"from\": \"YYYY-MM-DD HH:MM\", \"to\": \"...\"}\n"
            ."filter = {field, op, value}; op для измерений: =,!=,in,not_in; для метрик: =,!=,>,>=,<,<=,between\n"
            ."aggregation = {fn, field?, alias, filters?}; fn: ".implode(', ', $fns)."\n"
            ."  count_if несёт собственный filters (для «числа нарушений»).\n"
            ."  compare_previous_period — только для viz stat; sample — 0.1 или 0.01.\n"
            ."time_bucket: null | hour | day | week | month\n\n"
            ."Визуализации:\n".implode("\n", $viz);
    }

    private function alertFormat(): string
    {
        $lines = [];
        foreach (NodeCatalog::all() as $node) {
            $params = [];
            foreach ($node['params'] as $p) {
                $req = ($p['required'] ?? false) ? '*' : '';
                $vals = isset($p['values']) ? ' ('.implode('/', array_map(strval(...), $p['values'])).')' : '';
                $params[] = $p['name'].$req.':'.$p['type'].$vals;
            }
            $ports = isset($node['ports']) ? ' выходы: '.implode('/', $node['ports']) : '';
            $lines[] = "- [{$node['category']}] {$node['type']}{$ports} — ".implode(', ', $params);
        }

        return "# КАТАЛОГ УЗЛОВ АЛЕРТА (звёздочка = обязательный параметр)\n\n"
            ."config = {version, name, description, enabled, nodes: [...], edges: [...]}\n"
            ."node = {id, type, position: {x, y}, params}\n"
            ."edge = {from, to} или {from, to, from_port} для condition\n\n"
            .implode("\n", $lines);
    }

    private function examples(string $kind, int $limit): string
    {
        $files = glob($this->examplesDir.'/'.$kind.'/*.json') ?: [];
        sort($files);
        $blocks = [];
        foreach (\array_slice($files, 0, $limit) as $file) {
            $config = json_decode(file_get_contents($file), true);
            $prompt = $config['description'] ?? $config['name'];
            $blocks[] = "Запрос: {$prompt}\nОтвет config:\n".json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return "# ПРИМЕРЫ\n\n".implode("\n\n", $blocks);
    }
}
