# API и веб-интерфейс

Все ответы API — `application/json`. Ошибки — `{"error": "текст"}` с кодом 4xx/5xx.
Идентификатор события везде — `event_id` (UUID).

## Фильтр периода (общий для всех эндпоинтов и страниц)

Опциональные query-параметры `from` и `to` (формат `2026-07-14T12:30`, как у
`<input type="datetime-local">`; секунды допустимы) ограничивают выборку по
`event_time`. Парсинг и SQL-условия — в `App\TimeFilter`.

Зачем: сортировочный ключ таблицы начинается с `event_time`, поэтому такой
фильтр отсекает гранулы по первичному индексу — на десятках миллионов строк
графики ускоряются на порядок. Страницы прокидывают период насквозь: таблица →
ссылки строк → страница события → chart-запросы.

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

## 3. `GET /api/chart/{field}` — данные графика

Главный эндпоинт проекта. Сравнивает распределение поля `field` в двух группах:

- **similar** — события, у которых измерение `similar_by` равно `value`;
- **other** — все остальные события.

Query-параметры:

| Параметр | Описание |
|---|---|
| `similar_by` | обязательный; имя измерения из `Schema::DIMENSIONS` |
| `value` | обязательный; значение измерения (страница берёт его из открытого события) |
| `from`, `to` | опциональный период по `event_time` |

Эндпоинт нарочно не привязан к событию: страница события шлёт ~20 таких
запросов параллельно, и если бы каждый заново искал событие по UUID (полный
скан — id второй в сортировочном ключе), это заметно тормозило бы страницу.

Валидация: `field` ∈ `DIMENSIONS ∪ METRICS`, `similar_by` ∈ `DIMENSIONS`,
`field != similar_by`, `value` передан. Иначе 400. Имена колонок попадают в SQL
только после этой проверки по белому списку.

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
  "other_pct":   [3.1, 8.8, "…"],            // проценты от other_total
  "divergence": 48.0                         // расхождение распределений, 0–100
}
```

`divergence` — расстояние полной вариации `Σ|similar_pct − other_pct| / 2`:
0 — распределения совпадают, 100 — не пересекаются. Страница события
сортирует по нему графики (самые аномальные поля первыми) и подсвечивает
топ-3 с divergence ≥ 10.

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

- **Таймлайн** «события по часам» (Chart.js bar): читает не сырые события, а
  почасовые агрегаты из `events_by_hour` (см. DATA_MODEL.md §5) — тысячи строк
  вместо десятков миллионов, поэтому мгновенен на любом объёме. Столбцы внутри
  выбранного периода — синие, вне — серые. Выделение диапазона мышью
  (mousedown/mousemove/mouseup + отрисовка прямоугольника плагином Chart.js)
  устанавливает query-параметры `from`/`to` и перезагружает страницу.
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
   `DIMENSIONS ∪ METRICS`, кроме самого `similar_by`. Заголовок = имя поля +
   `Δ divergence%`. Когда все графики загружены, JS сортирует их по
   `divergence` (через CSS `order`) — самые аномальные поля первыми — и
   подсвечивает рамкой топ-3 с divergence ≥ 10.

`public/app.js` (единственный ручной JS, ~60 строк):

- читает из data-атрибутов контейнера `similar_by`, `similar_value`, период
  и список полей;
- для каждого поля делает `fetch('/api/chart/{field}?similar_by=…&value=…')`
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
