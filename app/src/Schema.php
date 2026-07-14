<?php

namespace App;

/**
 * Единственный источник правды о полях события (см. docs/DATA_MODEL.md §2).
 * Значения констант можно подставлять в SQL как имена колонок (белый список).
 */
final class Schema
{
    public const DIMENSIONS = [
        'event_type',
        'vehicle_id',
        'vehicle_type',
        'vehicle_make',
        'driver_id',
        'route_id',
        'region',
        'road_type',
        'weather',
        'last_fuel_station_id',
    ];

    public const METRICS = [
        'speed_kmh',
        'fuel_level_pct',
        'fuel_consumption_l100',
        'engine_temp_c',
        'engine_rpm',
        'cargo_weight_kg',
        'odometer_km',
        'ambient_temp_c',
        'tire_pressure_bar',
        'trip_duration_min',
        'harsh_events_cnt',
    ];

    public static function isDimension(string $field): bool
    {
        return \in_array($field, self::DIMENSIONS, true);
    }

    public static function isMetric(string $field): bool
    {
        return \in_array($field, self::METRICS, true);
    }
}
