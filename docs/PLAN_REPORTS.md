# План имплементации: конфигуратор репортов

ТЗ — [REPORTS.md](REPORTS.md). Правила те же, что в [PLAN.md](PLAN.md): фазы
идут по порядку, каждая заканчивается проверяемым состоянием, проверки
обязательны. **Этот план выполняется первым** из трёх: фаза R0 (хранилище
конфигов) и валидаторы нужны алертам и ИИ.

---

## Фаза R0 — хранилище конфигов (общее с алертами)

- [ ] R0.1 `docker/clickhouse/init/03_configs.sql` — DDL обеих таблиц
      (`report_configs`, `alert_configs`) из REPORTS.md §3
- [ ] R0.2 Применить DDL к живой базе вручную (init-скрипты выполняются только
      на чистом томе): `clickhouse-client --multiquery < .../03_configs.sql`
- [ ] R0.3 `app/src/Service/ConfigStore.php` — общий репозиторий поверх
      `ClickHouse`: `list(table)`, `get(table, id)`, `save(table, id, name, config)`
      (INSERT новой версии), `delete(table, id)` (INSERT с is_deleted=1).
      Чтение — всегда `FINAL WHERE is_deleted = 0`. Имя таблицы — из белого
      списка двух значений

**Проверка** (через clickhouse-client):

```sql
INSERT INTO report_configs (id, name, config) VALUES ('11111111-1111-1111-1111-111111111111', 'v1', '{}');
INSERT INTO report_configs (id, name, config) VALUES ('11111111-1111-1111-1111-111111111111', 'v2', '{}');
SELECT name FROM report_configs FINAL;               -- → только v2
SELECT count() FROM report_configs;                   -- → 2 (до слияния — норма)
INSERT INTO report_configs (id, name, config, is_deleted) VALUES ('11111111-1111-1111-1111-111111111111', 'v2', '{}', 1);
SELECT count() FROM report_configs FINAL WHERE is_deleted = 0;  -- → 0
-- после проверки: TRUNCATE TABLE report_configs
```

---

## Фаза R1 — формат и валидатор

- [ ] R1.1 `app/src/Reports/ReportSchema.php` — описание формата константами:
      список fn с требованиями к field, список op по типам полей, типы viz с
      правилами совместимости (REPORTS.md §4). Единственный источник и для
      валидатора, и для форм редактора, и для промпта ИИ
- [ ] R1.2 `app/src/Reports/ReportValidator.php` — `validate(array $config): array`
      (список ошибок с путями, пусто = валиден): поля по `Schema`, alias по
      regex, лимиты, совместимость viz ↔ query
- [ ] R1.3 Временная проверка без контроллера

**Проверка:**

```bash
docker compose exec php php -r '
require "vendor/autoload.php";
$v = new App\Reports\ReportValidator();
var_dump($v->validate(json_decode(file_get_contents("/dev/stdin"), true)));
' <<'JSON'
{"version":1,"name":"x","widgets":[{"title":"t","width":9,"query":{"time_range":{"last_hours":24},"filters":[{"field":"evil;DROP","op":"=","value":1}],"group_by":[],"aggregations":[{"fn":"avg","field":"speed_kmh","alias":"a"}]},"viz":{"type":"line"}}]}
JSON
# → ≥3 ошибки: width вне 1..3, поле evil;DROP, line без time_bucket
```

Валидный конфиг из REPORTS.md §4 → пустой список.

---

## Фаза R2 — QueryBuilder и выполнение виджета

- [ ] R2.1 `app/src/Reports/QueryBuilder.php` — конфиг query → `[sql, params]`
      по правилам REPORTS.md §5: серверные параметры для значений, time_bucket,
      sample (+домножение count/count_if/sum), sort/limit
- [ ] R2.2 `top_n_other` и `compare_previous_period`
- [ ] R2.3 `POST /api/report-data` (ApiController или новый ReportApiController):
      валидация query через ReportValidator → выполнение → ответ
      `{labels, series, meta.queries}` (формат под Chart.js; queryLog — в meta)

**Проверка:**

```bash
# средний расход по заправкам — FS-07 должен быть первым
curl -s -X POST http://localhost:8081/api/report-data -H 'Content-Type: application/json' -d '{
  "viz_type": "bar",
  "query": {"time_range": {"last_hours": 168}, "filters": [],
    "group_by": ["last_fuel_station_id"],
    "aggregations": [{"fn": "avg", "field": "fuel_consumption_l100", "alias": "l100"}],
    "sort": {"by": "l100", "dir": "desc"}, "limit": 10}}' | python3 -m json.tool | head -20

# line по дням; sample 0.01 быстрее полного; stat с compare_previous_period возвращает дельту
# инъекция: {"group_by": ["region; DROP TABLE events"]} → 422 от валидатора
```

---

## Фаза R3 — CRUD API

- [ ] R3.1 `GET/POST /api/reports`, `GET/PUT/DELETE /api/reports/{id}` поверх
      `ConfigStore` (REPORTS.md §6); POST/PUT прогоняют весь конфиг через
      валидатор → 422 с ошибками

**Проверка:**

```bash
ID=$(curl -s -X POST http://localhost:8081/api/reports -d @docs/examples/reports/fuel_by_station.json | python3 -c 'import sys,json;print(json.load(sys.stdin)["id"])')
curl -s http://localhost:8081/api/reports | python3 -m json.tool          # список содержит репорт
curl -s -X PUT http://localhost:8081/api/reports/$ID -d '{"name":"x"}'   # → 422 (нет widgets)
curl -s -X DELETE http://localhost:8081/api/reports/$ID -o /dev/null -w '%{http_code}\n'  # 200; из списка исчез
```

---

## Фаза R4 — эталонные примеры и просмотр

- [ ] R4.1 `docs/examples/reports/*.json` — 5 конфигов из REPORTS.md §8,
      каждый проверен через `POST /api/report-data`
- [ ] R4.2 Страница `GET /reports` (список + кнопки) и ссылки «Репорты» в шапке
- [ ] R4.3 Страница `GET /report/{id}` — сетка виджетов; JS: обход widgets[],
      параллельные POST /api/report-data, рендер по viz.type (line/bar/
      stacked_bar/stat/table/heatmap); категориальная палитра — константа
- [ ] R4.4 Загрузить 5 примеров через API

**Проверка:** все 5 репортов открываются, каждый тип визуализации встречается
хотя бы раз и выглядит осмысленно (скриншот страницы «Обзор парка»); в консоли
браузера нет ошибок; под виджетами — строка «прочитано N строк · мс».

---

## Фаза R5 — редактор

- [ ] R5.1 `GET /report/{id}/edit` + `GET /report/new`: layout из трёх зон
      (метаданные+список виджетов / форма виджета+предпросмотр / место под чат ИИ)
- [ ] R5.2 Форма виджета генерируется из `ReportSchema` (селекты полей/fn/op/viz);
      добавление/удаление/перестановка виджетов и агрегаций/фильтров
- [ ] R5.3 Живой предпросмотр выбранного виджета (debounce 500мс → /api/report-data)
- [ ] R5.4 Вкладка «JSON»: textarea + «применить» (валидация, ошибки списком)
- [ ] R5.5 «Сохранить» → POST/PUT; ошибки 422 отображаются

**Проверка (в браузере):** пересобрать репорт №3 («Расход по заправкам») с нуля
через формы; изменить период через JSON-вкладку; сломать конфиг в JSON → внятная
ошибка; сохранить, открыть просмотр.

---

## Фаза R6 — приёмка

Критерии REPORTS.md §9, все шесть пунктов, плюс:

- [ ] R6.1 Чистый старт с нуля (`down -v` → up → composer → генерация →
      загрузка примеров) — таблицы конфигов создаются init-скриптом
- [ ] R6.2 Обновить README (раздел «что пощупать»: ReplacingMergeTree + FINAL)
