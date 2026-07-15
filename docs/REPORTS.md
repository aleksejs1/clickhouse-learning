# ТЗ: Конфигуратор репортов

## 1. Цель и концепция

Аналог Boards в Honeycomb: вместо предустановленных репортов с парой чекбоксов —
конструктор, где пользователь собирает репорт любой конфигурации из событий
телеметрии. **Репорт = именованный набор виджетов**, каждый виджет =
декларативный запрос к `events` + визуализация.

Ключевое решение: конфигурация репорта — это **один JSON-документ**, полностью
описывающий и запросы, и внешний вид. С этим форматом работают все три
потребителя одинаково:

1. UI-редактор (формы читают/пишут JSON);
2. API (CRUD + выполнение);
3. ИИ-ассистент (генерирует тот же JSON по свободному тексту, см. [AI_ASSISTANT.md](AI_ASSISTANT.md)).

Никакого SQL в конфиге нет и быть не может — только декларативные поля,
проверяемые по белым спискам. SQL собирает сервер.

## 2. Стек

Без новых технологий: Twig + Chart.js (CDN) + ванильный JS, серверная сборка SQL
в PHP. Хранение конфигов — в ClickHouse (см. §3): других БД в проекте нет,
и это повод изучить паттерн «изменяемые данные в ClickHouse».

## 3. Хранение конфигов: ReplacingMergeTree

ClickHouse не умеет UPDATE в OLTP-смысле. Стандартный паттерн для редко
изменяемых сущностей — `ReplacingMergeTree`: обновление = INSERT новой версии
строки, при фоновых слияниях остаётся строка с максимальным `updated_at`,
а при чтении дубли схлопываются модификатором `FINAL`.

`docker/clickhouse/init/03_configs.sql`:

```sql
CREATE TABLE IF NOT EXISTS telemetry.report_configs
(
    id         UUID,
    name       String,
    config     String,              -- JSON конфигурации целиком
    is_deleted UInt8 DEFAULT 0,     -- soft delete
    updated_at DateTime DEFAULT now()
)
ENGINE = ReplacingMergeTree(updated_at)
ORDER BY id;

-- та же схема для алертов (см. ALERTS.md)
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
```

Правила работы (учебные моменты — задокументировать в коде):

- чтение: `SELECT ... FROM report_configs FINAL WHERE is_deleted = 0`;
- обновление: обычный `INSERT` с тем же `id` и новым `updated_at`;
- удаление: `INSERT` с `is_deleted = 1`;
- `FINAL` на таблице в тысячи строк дёшев; на `events` он был бы недопустим.

## 4. Формат конфигурации репорта

```json
{
  "version": 1,
  "name": "Расход топлива по парку",
  "description": "Недельный обзор для механика",
  "widgets": [
    {
      "title": "Средний расход по заправкам",
      "width": 2,
      "query": {
        "time_range": {"last_hours": 168},
        "filters": [
          {"field": "event_type", "op": "in", "value": ["trip_end", "gps_ping"]}
        ],
        "group_by": ["last_fuel_station_id"],
        "time_bucket": null,
        "aggregations": [
          {"fn": "avg", "field": "fuel_consumption_l100", "alias": "avg_l100"}
        ],
        "sort": {"by": "avg_l100", "dir": "desc"},
        "limit": 20,
        "sample": null
      },
      "viz": {"type": "bar"}
    }
  ]
}
```

### Справочник полей

**`widget`**

| Поле | Тип | Описание |
|---|---|---|
| `title` | string | заголовок виджета |
| `width` | 1 \| 2 \| 3 | ширина в колонках сетки из 3 |
| `query` | Query | запрос (ниже) |
| `viz` | Viz | визуализация (ниже) |

**`query`**

| Поле | Тип | Описание |
|---|---|---|
| `time_range` | `{"last_hours": N}` или `{"from": "...", "to": "..."}` | период по `event_time` |
| `filters` | Filter[] | все условия соединяются AND; 0..10 |
| `group_by` | string[] | 0..2 измерения из `Schema::DIMENSIONS` |
| `time_bucket` | null \| `hour` \| `day` \| `week` \| `month` | добавляет группировку по времени (`toStartOf*`) |
| `aggregations` | Agg[] | 1..5 агрегаций |
| `sort` | `{"by": alias, "dir": "asc"|"desc"}` | сортировка по алиасу агрегации |
| `limit` | int 1..1000 | ограничение групп |
| `top_n_other` | bool | группы за пределами limit схлопнуть в строку `other` (для bar/stacked_bar) |
| `compare_previous_period` | bool | посчитать то же за предыдущий период, вернуть дельту (только для stat) |
| `sample` | null \| 0.1 \| 0.01 | сэмплирование (см. DATA_MODEL.md §8), счётчики домножаются |

**`filter`**: `{"field": ..., "op": ..., "value": ...}`

- `field` ∈ `DIMENSIONS ∪ METRICS`;
- для измерений: `=`, `!=`, `in`, `not_in`;
- для метрик: `=`, `!=`, `>`, `>=`, `<`, `<=`, `between` (value = [min, max]).

**`aggregation`**: `{"fn": ..., "field": ..., "alias": ..., "filters": [...]}`

| `fn` | `field` | SQL | Зачем в домене |
|---|---|---|---|
| `count` | — | `count()` | число событий |
| `uniq` | dimension | `uniq(field)` | «машин на линии», «активных водителей» |
| `sum` | metric | `sum(field)` | суммарный вес груза |
| `avg` / `min` / `max` | metric | `avg(field)` … | средний расход, максимальная скорость |
| `median` | metric | `quantile(0.5)(field)` | устойчивая середина |
| `p95` | metric | `quantile(0.95)(field)` | хвосты (перегревы, превышения) |
| `count_if` | — | `countIf(<filters>)` | «число резких торможений»: собственный список `filters` |
| `max_minus_min` | metric | `max(field) - min(field)` | пробег за период из `odometer_km` |

`alias` — латиница/цифры/подчёркивание, уникален в пределах query (белый список
символов, попадает в SQL как имя колонки только после проверки regex
`^[a-z][a-z0-9_]{0,30}$`).

**`viz`**: `{"type": ...}` + опции

| `type` | Требования к query | Отрисовка |
|---|---|---|
| `table` | любой | HTML-таблица: группы + агрегации |
| `line` | `time_bucket` обязателен; group_by 0..1 | линии по времени, серия на группу |
| `bar` | group_by = 1, без time_bucket | столбцы по группам |
| `stacked_bar` | `time_bucket` + group_by = 1 | стек по времени |
| `stat` | без group_by и time_bucket, 1 агрегация | плитка-число (+ дельта к пред. периоду) |
| `heatmap` | `time_bucket` + group_by = 1 | матрица время × группа, цвет = значение (рисуется таблицей с фоном ячеек, не Chart.js) |

Валидация всего конфига — класс `App\Reports\ReportValidator`: белые списки
полей, совместимость viz ↔ query, лимиты. Возвращает список ошибок с путём
до поля (`widgets[0].query.group_by: unknown field "driver"`); эти же ошибки
скармливаются ИИ при повторной попытке.

## 5. Сборка SQL (`App\Reports\QueryBuilder`)

Правила:

- значения фильтров — только серверные параметры `{p0:String}`, `{p1:Float64}`…;
- имена колонок/функций — только из белых списков после валидации;
- `time_bucket` → `toStartOfHour(event_time) AS t` и т.д., всегда первым в GROUP BY/ORDER BY;
- `sample` → `SAMPLE 0.1` + домножение счётных агрегаций (`count`, `count_if`, `sum`) на 1/rate;
- `top_n_other`: два запроса — топ-N групп, затем агрегация с `if(group IN топ, group, 'other')`
  (или одним запросом через подзапрос — на усмотрение исполнителя);
- `compare_previous_period`: тот же запрос со сдвинутым на длину периода time_range, дельта в PHP.

Пример: query из §4 превращается в

```sql
SELECT last_fuel_station_id, avg(fuel_consumption_l100) AS avg_l100
FROM events
WHERE event_time >= now() - INTERVAL 168 HOUR
  AND event_type IN ({p0:String}, {p1:String})
GROUP BY last_fuel_station_id
ORDER BY avg_l100 DESC
LIMIT 20
```

## 6. API

| Метод и путь | Что делает |
|---|---|
| `GET /api/reports` | список: `[{id, name, description, updated_at}]` (FINAL, без deleted) |
| `POST /api/reports` | создать: тело = конфиг; сервер генерит id; 422 + ошибки валидатора |
| `GET /api/reports/{id}` | конфиг целиком |
| `PUT /api/reports/{id}` | обновить (INSERT новой версии); 422 при невалидном |
| `DELETE /api/reports/{id}` | soft delete |
| `POST /api/report-data` | выполнить **один** query-конфиг: тело `{query, viz_type}` → `{labels, series: [{name, data}], meta: {queries}}` — формат, готовый для Chart.js; используется и просмотром, и живым предпросмотром редактора |

`meta.queries` — знакомая панель `X-ClickHouse-Summary` (queryLog), показывается
под каждым виджетом.

## 7. Web UI

- **`GET /reports`** — список: имя, описание, дата, кнопки «открыть / редактировать /
  удалить», «создать», «создать с ИИ» (см. AI_ASSISTANT.md). Ссылка в шапке сайта.
- **`GET /report/{id}`** — просмотр: сетка из 3 колонок, каждый виджет = `<canvas>`
  или таблица; JS обходит `widgets[]`, дергает `/api/report-data` параллельно
  и рисует. Цвета серий — фиксированная категориальная палитра проекта
  (первый — `#5b8cc4`, акцент — `#f59e0b`; палитру из 6 цветов задать константой
  в app.js и никогда не перецикливать).
- **`GET /report/{id}/edit`** — редактор:
  - слева: имя/описание, список виджетов (добавить/удалить/сдвинуть вверх-вниз);
  - выбранный виджет: форма, один в один повторяющая структуру query/viz
    (селекты полей — из `Schema`, прокинутых в шаблон);
  - под формой: **живой предпросмотр** (fetch `/api/report-data` при изменении);
  - вкладка «JSON»: `<textarea>` с конфигом целиком — прямое редактирование
    (двусторонняя синхронизация с формой не обязательна: кнопка «применить JSON»);
  - справа: чат-панель ИИ (см. AI_ASSISTANT.md);
  - «Сохранить» → PUT, ошибки валидатора показываются списком.

Редактор — самая большая JS-часть проекта (~300 строк). Всё равно без
фреймворков: формы генерируются из тех же констант-описаний, что и валидатор.

## 8. Примеры репортов (эталонные конфиги)

Хранить в `docs/examples/reports/*.json` — они же few-shot примеры для ИИ:

1. **Обзор парка** — 4 stat-плитки (событий за сутки; `uniq(vehicle_id)`;
   `avg(fuel_consumption_l100)`; `count_if` нарушений) с `compare_previous_period`
   + line «события по часам».
2. **Рейтинг водителей по нарушениям** — bar: `count_if(event_type in
   [harsh_braking, speeding])` group by `driver_id`, топ-15.
3. **Расход по заправкам** — bar из §4 + table с median и p95.
4. **Пробег машин за неделю** — table: `max_minus_min(odometer_km)` group by
   `vehicle_id`, сортировка по убыванию.
5. **Нарушения: время × регион** — heatmap: count_if по `time_bucket=hour`? нет —
   `day` × `region` за 30 дней.

## 9. Критерии приёмки

- Все 5 эталонных репортов создаются через UI-редактор и отображаются корректно.
- Конфиг с чужим именем поля/алиасом `evil; DROP` отклоняется валидатором с 422.
- Редактирование сохраняется как новая версия (видно по `updated_at`), удаление —
  soft delete; после `OPTIMIZE TABLE report_configs FINAL` дубли схлопываются.
- Предпросмотр виджета обновляется без сохранения репорта.
- `POST /api/report-data` со `sample: 0.01` на полном датасете отвечает < 0.5с.
