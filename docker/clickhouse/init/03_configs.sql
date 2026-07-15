-- Конфиги репортов и алертов (см. docs/REPORTS.md §3).
--
-- ClickHouse не про UPDATE: паттерн для редко изменяемых сущностей —
-- ReplacingMergeTree. Обновление = INSERT новой версии строки с бОльшим
-- updated_at; при фоновых слияниях остаётся последняя версия. Слияния
-- асинхронны, поэтому при чтении обязателен модификатор FINAL (на таблицах
-- в тысячи строк он дёшев; на events был бы недопустим).
-- Удаление — soft delete: INSERT с is_deleted = 1.

CREATE TABLE IF NOT EXISTS telemetry.report_configs
(
    id         UUID,
    name       String,
    config     String,              -- JSON конфигурации целиком
    is_deleted UInt8 DEFAULT 0,
    updated_at DateTime DEFAULT now()
)
ENGINE = ReplacingMergeTree(updated_at)
ORDER BY id;

CREATE TABLE IF NOT EXISTS telemetry.alert_configs
(
    id         UUID,
    name       String,
    config     String,
    is_deleted UInt8 DEFAULT 0,
    updated_at DateTime DEFAULT now()
)
ENGINE = ReplacingMergeTree(updated_at)
ORDER BY id;
