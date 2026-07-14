CREATE DATABASE IF NOT EXISTS telemetry;

-- Кодеки выбраны по замерам на этих данных (см. docs/DATA_MODEL.md §6):
--  - DoubleDelta на event_time: колонка отсортирована, дельты крошечные — сжатие ×68 вместо ×10;
--  - T64 на целочисленных: обрезает неиспользуемые старшие биты (cargo ×2.1 вместо ×1.1);
--  - Gorilla на Float32 ПРОИГРАЛ обычному LZ4 (значения случайны от строки к строке) — не применяем.
-- Bloom-индекс ускоряет точечный поиск по event_id: без него это полный скан,
-- т.к. event_id не входит в сортировочный ключ и первичный индекс не помогает.
-- TTL: события старше 90 дней ClickHouse удаляет сам при фоновых слияниях.
-- Сортировочный ключ (toStartOfHour(event_time), xxHash32(event_id)) + SAMPLE BY:
-- ключ сэмплирования обязан входить в первичный ключ, и он нарочно стоит после
-- ЧАСА, а не после сырого event_time — внутри часа ~сотни тысяч строк
-- отсортированы по хэшу, и SAMPLE 0.1 реально пропускает гранулы. С ключом
-- (event_time, hash) сэмплирование читало бы все гранулы (в одной секунде
-- слишком мало строк) — проверено замерами, см. docs/DATA_MODEL.md §8.
CREATE TABLE IF NOT EXISTS telemetry.events
(
    event_id              UUID,
    event_time            DateTime CODEC(DoubleDelta, LZ4),
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

    -- гео (не входят в METRICS — см. docs/DATA_MODEL.md §7)
    lat                   Float32,
    lon                   Float32,

    -- метрики (metrics)
    speed_kmh             Float32,
    fuel_level_pct        Float32,
    fuel_consumption_l100 Float32,
    engine_temp_c         Float32,
    engine_rpm            UInt16 CODEC(T64, LZ4),
    cargo_weight_kg       UInt32 CODEC(T64, LZ4),
    odometer_km           UInt32 CODEC(T64, LZ4),
    ambient_temp_c        Float32,
    tire_pressure_bar     Float32,
    trip_duration_min     UInt16 CODEC(T64, LZ4),
    harsh_events_cnt      UInt8 CODEC(T64, LZ4),

    INDEX idx_event_id event_id TYPE bloom_filter GRANULARITY 4
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(event_time)
ORDER BY (toStartOfHour(event_time), xxHash32(event_id))
SAMPLE BY xxHash32(event_id)
TTL event_time + INTERVAL 90 DAY;
