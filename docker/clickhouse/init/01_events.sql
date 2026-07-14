CREATE DATABASE IF NOT EXISTS telemetry;

CREATE TABLE IF NOT EXISTS telemetry.events
(
    event_id              UUID,
    event_time            DateTime,
    event_type            LowCardinality(String),

    -- измерения (dimensions)
    vehicle_id            LowCardinality(String),
    vehicle_type          LowCardinality(String),
    vehicle_make          LowCardinality(String),
    driver_id             LowCardinality(String),
    route_id              LowCardinality(String),
    region                LowCardinality(String),
    road_type             LowCardinality(String),
    weather               LowCardinality(String),
    last_fuel_station_id  LowCardinality(String),

    -- метрики (metrics)
    speed_kmh             Float32,
    fuel_level_pct        Float32,
    fuel_consumption_l100 Float32,
    engine_temp_c         Float32,
    engine_rpm            UInt16,
    cargo_weight_kg       UInt32,
    odometer_km           UInt32,
    ambient_temp_c        Float32,
    tire_pressure_bar     Float32,
    trip_duration_min     UInt16,
    harsh_events_cnt      UInt8
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(event_time)
ORDER BY (event_time, event_id);
