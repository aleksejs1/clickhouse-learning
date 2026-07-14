-- Почасовые агрегаты для таймлайна на главной странице.
--
-- Материализованное представление (MV) в ClickHouse — это триггер на INSERT:
-- каждая пачка, вставляемая в events, на лету агрегируется и дописывается в
-- events_by_hour. SummingMergeTree при фоновых слияниях складывает строки с
-- одинаковым ключом (event_type, hour), поэтому при чтении всё равно нужен
-- GROUP BY/sum() — слияния асинхронны.
--
-- ВАЖНО: MV видит только новые вставки. При создании поверх существующих
-- данных нужен разовый backfill:
--   INSERT INTO telemetry.events_by_hour
--   SELECT toStartOfHour(event_time) AS hour, event_type, count() AS events
--   FROM telemetry.events GROUP BY hour, event_type;

CREATE TABLE IF NOT EXISTS telemetry.events_by_hour
(
    hour       DateTime,
    event_type LowCardinality(String),
    events     UInt64
)
ENGINE = SummingMergeTree
ORDER BY (event_type, hour);

CREATE MATERIALIZED VIEW IF NOT EXISTS telemetry.events_by_hour_mv
TO telemetry.events_by_hour AS
SELECT
    toStartOfHour(event_time) AS hour,
    event_type,
    count() AS events
FROM telemetry.events
GROUP BY hour, event_type;
