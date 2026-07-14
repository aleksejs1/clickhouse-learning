# API и веб-интерфейс

Все ответы API — `application/json`. Ошибки — `{"error": "текст"}` с кодом 4xx/5xx.
Идентификатор события везде — `event_id` (UUID).

## 1. `GET /api/events`

Список событий для таблицы.

Query-параметры (все опциональны):

| Параметр | Тип | Дефолт | Описание |
|---|---|---|---|
| `limit` | int 1–200 | 50 | размер страницы |
| `offset` | int | 0 | смещение |
| `event_type` | string | — | фильтр по типу |
| `vehicle_id` | string | — | фильтр по машине |
| `driver_id` | string | — | фильтр по водителю |

Ответ:

```json
{
  "items": [ { "event_id": "…", "event_time": "2026-07-13 18:02:11", "event_type": "trip_end", "...": "все поля события" } ],
  "total": 123456
}
```

SQL: `SELECT * FROM events WHERE … ORDER BY event_time DESC LIMIT {limit:UInt32} OFFSET {offset:UInt32}`
плюс отдельный `SELECT count()` с теми же фильтрами. Фильтры — только через
серверные параметры (`event_type = {event_type:String}`).

## 2. `GET /api/events/{id}`

Одно событие, плоский JSON со всеми полями. 404, если не найдено.

## 3. `GET /api/events/{id}/chart/{field}` — данные графика

Главный эндпоинт проекта. Сравнивает распределение поля `field` в двух группах:

- **similar** — события, у которых значение измерения `similar_by` совпадает
  со значением у события `{id}`;
- **other** — все остальные события.

Query-параметры:

| Параметр | Описание |
|---|---|
| `similar_by` | обязательный; имя измерения из `Schema::DIMENSIONS` |

Валидация: `field` ∈ `DIMENSIONS ∪ METRICS`, `similar_by` ∈ `DIMENSIONS`,
`field != similar_by`. Иначе 400. Имена колонок попадают в SQL только после
этой проверки по белому списку.

Ответ (одинаковая форма для обоих видов полей):

```json
{
  "field": "fuel_consumption_l100",
  "kind": "metric",                          // или "dimension"
  "similar_by": "last_fuel_station_id",
  "similar_value": "FS-07",
  "similar_total": 61234,
  "other_total": 938766,
  "labels": ["24.1–25.3", "25.3–26.5", "…"], // значения измерения или границы корзин
  "similar_pct": [1.2, 4.5, "…"],            // проценты от similar_total
  "other_pct":   [3.1, 8.8, "…"]             // проценты от other_total
}
```

Нормировка в проценты обязательна: групп «похожих» обычно в разы меньше, в
абсолютных числах сравнивать распределения невозможно.

### SQL для категориального поля (`kind: dimension`)

```sql
SELECT
    region                                            AS label,
    countIf(last_fuel_station_id  = {val:String})     AS similar,
    countIf(last_fuel_station_id != {val:String})     AS other
FROM events
GROUP BY label
ORDER BY similar + other DESC
LIMIT 20
```

(`region` и `last_fuel_station_id` здесь — подставленные из белого списка
`field` и `similar_by`.)

### SQL для числового поля (`kind: metric`) — гистограмма на 20 корзин

```sql
WITH
    (SELECT min({field}) FROM events) AS mn,
    (SELECT max({field}) FROM events) AS mx
SELECT
    widthBucket({field}, mn, mx + 0.001, 20)          AS bucket,
    any(mn) AS mn_, any(mx) AS mx_,
    countIf({similar_by}  = {val:String})             AS similar,
    countIf({similar_by} != {val:String})             AS other
FROM events
GROUP BY bucket
ORDER BY bucket
```

Подписи корзин (`labels`) считает PHP из `mn_`/`mx_`: ширина корзины
`(mx - mn) / 20`. Пустые корзины дозаполнить нулями, чтобы у всех графиков
было ровно 20 точек.

## 4. Страницы (HTML, Twig)

### `GET /` — таблица событий

- **Блок статистики** над таблицей, 4 плитки:
  1. событий в базе;
  2. полей в событии (из `Schema`: измерения + метрики + id и время);
  3. размер на диске + без сжатия — из `system.parts`:
     `SELECT sum(rows), sum(bytes_on_disk), sum(data_uncompressed_bytes)
     FROM system.parts WHERE database = currentDatabase() AND table = 'events' AND active`;
  4. оценка экспорта в JSON по формуле «строк × средний размер JSON-строки»,
     где средний размер — по выборке:
     `SELECT round(avg(length(formatRow('JSONEachRow', *)))) FROM (SELECT * FROM events LIMIT 1000)`;
     рядом — во сколько раз это больше размера на диске (наглядно показывает
     колоночное сжатие ClickHouse).
- Таблица: `event_time`, `event_type`, `vehicle_id`, `driver_id`, `route_id`,
  `region`, `speed_kmh`, `fuel_consumption_l100`.
- Сверху `<select>` фильтра по `event_type` (значения захардкожены в шаблоне
  из `Schema`) и кнопки пагинации «новее / старше» (через query-параметры
  страницы, серверный рендеринг — JS тут не нужен).
- Клик по строке → `/event/{event_id}`.

### `GET /event/{id}` — страница события

1. **Карточка события** — все поля в две колонки (простой `<dl>`).
2. **Селектор сходства** — `<select name="similar_by">` со всеми измерениями,
   кроме `event_id`/`event_time`; дефолт `vehicle_id`; выбор меняет
   query-параметр и перезагружает страницу (или перерисовывает графики — как
   проще).
3. **Сетка графиков** — CSS grid, по одному `<canvas>` на каждое поле из
   `DIMENSIONS ∪ METRICS`, кроме самого `similar_by`. Заголовок = имя поля.

`public/app.js` (единственный ручной JS, ~60 строк):

- читает из data-атрибутов контейнера `event_id`, `similar_by` и список полей;
- для каждого поля делает `fetch('/api/events/{id}/chart/{field}?similar_by=…')`
  параллельно;
- рисует Chart.js `bar`-график: два dataset'а —
  «similar (N=…)» цветом `#f59e0b` (янтарный) и «other (N=…)» цветом `#5b8cc4`
  (серо-голубой), ось Y — проценты.

Chart.js подключается в `base.html.twig` одной строкой с CDN
(`https://cdn.jsdelivr.net/npm/chart.js@4`). Стили — один `<style>`-блок в
`base.html.twig`, без CSS-фреймворков.

### Сценарий проверки UX (ручной приёмочный тест)

1. Открыть `/`, отфильтровать `trip_end`, открыть любое событие.
2. В селекторе выбрать `last_fuel_station_id`.
3. На графике `fuel_consumption_l100`: если событие с `FS-07` — янтарное
   распределение заметно правее серого; если с другой заправки — совпадают.
4. Аналогично проверить `driver_id` = D-13 (график `harsh_events_cnt`,
   `speed_kmh`) и `route_id` = R-04 (график `trip_duration_min`).
