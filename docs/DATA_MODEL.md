# Модель данных и генератор

## 1. Таблица `telemetry.events`

Одна широкая денормализованная таблица — как принято в ClickHouse и как устроены
события в Honeycomb. Файл `docker/clickhouse/init/01_events.sql`:

```sql
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
```

Что здесь учебного: `LowCardinality` для строковых измерений, партиционирование
по месяцам, сортировочный ключ по времени (основной паттерн доступа — «последние
события» и полные сканы с агрегацией).

## 2. Словарь полей

Эти же списки — константы `Schema::DIMENSIONS` и `Schema::METRICS` в PHP.

### Измерения (категориальные)

| Поле | Значения | Комментарий |
|---|---|---|
| `event_type` | `gps_ping`, `trip_start`, `trip_end`, `refuel`, `harsh_braking`, `speeding`, `idle`, `engine_fault` | Веса: gps_ping ~60%, trip_start/trip_end по ~8%, refuel ~6%, остальные по ~4–5% |
| `vehicle_id` | `V-001`…`V-050` | 50 машин |
| `vehicle_type` | `truck`, `van`, `refrigerated_truck`, `tanker` | Детерминированно от vehicle_id (одна машина — один тип) |
| `vehicle_make` | `volvo`, `scania`, `man`, `mercedes`, `daf` | Детерминированно от vehicle_id |
| `driver_id` | `D-01`…`D-30` | 30 водителей |
| `route_id` | `R-01`…`R-15` | 15 маршрутов |
| `region` | `riga`, `kurzeme`, `latgale`, `vidzeme`, `zemgale` | Детерминированно от route_id |
| `road_type` | `highway`, `city`, `rural` | |
| `weather` | `clear`, `rain`, `snow`, `fog` | clear ~55%, rain ~25%, snow ~12%, fog ~8% |
| `last_fuel_station_id` | `FS-01`…`FS-10` | **Заправка последней заправки этой машины.** Проставляется на всех событиях, не только на `refuel` — иначе аномалию «плохое топливо» нельзя было бы увидеть на расходе |

«Детерминированно от X» означает простую формулу вида
`TYPES[crc32(vehicleId) % count(TYPES)]` — чтобы у одной машины атрибуты не
скакали от события к событию, но не пришлось хранить состояние.

### Метрики (числовые)

| Поле | Базовое распределение (норм. = normal(μ, σ), клип в разумных границах) |
|---|---|
| `speed_kmh` | highway: норм.(78, 10); city: норм.(35, 9); rural: норм.(55, 10); при `idle`/`refuel` = 0 |
| `fuel_level_pct` | равномерно 5–100; сразу после `refuel` 85–100 |
| `fuel_consumption_l100` | truck/tanker: норм.(28, 3); refrigerated_truck: норм.(33, 3); van: норм.(14, 2) |
| `engine_temp_c` | норм.(90, 4) |
| `engine_rpm` | норм.(1400, 250), при стоянке норм.(800, 60) |
| `cargo_weight_kg` | van: равномерно 0–1 500; иначе равномерно 0–24 000 |
| `odometer_km` | 50 000–450 000, детерминированная база от vehicle_id + шум |
| `ambient_temp_c` | норм.(12, 8); при `weather = snow` норм.(-4, 3) |
| `tire_pressure_bar` | норм.(8.5, 0.4) |
| `trip_duration_min` | норм.(180, 60), клип 10–600; ненулевое только у `trip_end` |
| `harsh_events_cnt` | Пуассон-подобное: 0 с вер. 70%, 1 — 20%, 2 — 7%, 3–5 — 3% |

## 3. Заложенные аномалии

Смысл проекта — чтобы эти корреляции были видны на графиках сравнения.
Генератор применяет модификаторы **после** базовой генерации:

| # | Условие | Эффект | Как увидеть в UI |
|---|---|---|---|
| A1 | `last_fuel_station_id = 'FS-07'` | `fuel_consumption_l100` × 1.20 (плохое топливо) | Открыть любое событие, сходство = `last_fuel_station_id`: у FS-07 гистограмма расхода сдвинута вправо |
| A2 | `driver_id = 'D-13'` | `harsh_events_cnt` + 2, `speed_kmh` × 1.15; 20% его `gps_ping` заменяются на `harsh_braking`/`speeding` | Сходство = `driver_id`: у D-13 больше `harsh_braking`/`speeding` и выше скорость |
| A3 | `route_id = 'R-04'` | `speed_kmh` × 0.7, `trip_duration_min` × 1.5 (разбитая дорога) | Сходство = `route_id` |
| A4 | `vehicle_make = 'daf'` и `ambient_temp_c > 20` | `engine_temp_c` + 8 (слабое охлаждение) | Сходство = `vehicle_make` на летних событиях |

Плюс «естественные» корреляции из базовых распределений (снег → холоднее и
медленнее, city → ниже скорость): полезно, чтобы не каждый график выглядел
идеально ровным.

## 4. Генератор (`app:generate-events <count>`)

Алгоритм одного события (состояние между событиями не хранится — этого достаточно
для учебных целей):

1. Случайные `event_time` (равномерно за последние 30 дней), `vehicle_id`,
   `driver_id`, `route_id`, `road_type`, `weather`, `event_type` (по весам),
   `last_fuel_station_id`.
2. Детерминированные от них: `vehicle_type`, `vehicle_make`, `region`.
3. Метрики по базовым распределениям из §2.
4. Модификаторы аномалий A1–A4.
5. `event_id = Uuid::v4()`.

Реализация: генератор-функция `yield`-ит строки-массивы, команда копит пачку в
10 000 строк и шлёт `ClickHouse::insertBatch('events', $batch)`. Прогресс —
стандартный `ProgressBar` из symfony/console. Никаких библиотек типа Faker —
`mt_rand` и пара хелперов (`normal(μ, σ)` через Бокса–Мюллера, `pick(массив)`,
`weighted(массив => вес)`) короче любой зависимости.

Проверка после генерации:

```sql
SELECT event_type, count() FROM telemetry.events GROUP BY event_type;

-- аномалия A1 должна быть видна и в SQL:
SELECT last_fuel_station_id, round(avg(fuel_consumption_l100), 1) AS avg_l100
FROM telemetry.events GROUP BY last_fuel_station_id ORDER BY avg_l100 DESC;
```
