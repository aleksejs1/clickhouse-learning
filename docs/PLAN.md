# План имплементации

Порядок обязателен: каждая фаза заканчивается работающим, проверяемым состоянием
и опирается на предыдущую. Внутри фазы шаги отмечаются чекбоксами по мере
выполнения. Проверка в конце фазы — обязательная часть фазы, а не рекомендация.

Ссылки: [SPEC.md](SPEC.md) — структура и стек, [DATA_MODEL.md](DATA_MODEL.md) —
схема и генератор, [API.md](API.md) — эндпоинты и SQL.

---

## Фаза 0 — каркас репозитория

- [ ] `git init`, создать `.gitignore` (`app/vendor/`, `app/var/`, `.env.local`)
- [ ] Создать дерево каталогов из SPEC.md §3 (пустые `docker/`, `app/`, подкаталоги)

**Проверка:** `git status` показывает чистое дерево с документацией.

---

## Фаза 1 — Docker-инфраструктура и ClickHouse

Цель: три контейнера поднимаются, таблица создана, в неё можно писать руками.

- [ ] 1.1 `docker/clickhouse/init/01_events.sql` — DDL из DATA_MODEL.md §1
      (скопировать дословно, включая `IF NOT EXISTS`)
- [ ] 1.2 `docker/php/Dockerfile` — `php:8.3-fpm-alpine`, расширение `intl`,
      composer копией из образа `composer:2`
- [ ] 1.3 `docker/nginx/default.conf` — root `/app/public`, `try_files` на
      `index.php`, fastcgi на `php:9000`
- [ ] 1.4 `docker-compose.yml` — три сервиса по SPEC.md §4
- [ ] 1.5 Поднять: `docker compose up -d --build`

**Проверка:**

```bash
curl 'http://localhost:8123/ping'                 # → Ok.
docker compose exec clickhouse clickhouse-client -u app --password app -d telemetry \
  -q "SHOW CREATE TABLE events"                   # → DDL таблицы
docker compose exec clickhouse clickhouse-client -u app --password app -d telemetry \
  -q "INSERT INTO events (event_id, event_time, event_type) VALUES (generateUUIDv4(), now(), 'test')"
docker compose exec clickhouse clickhouse-client -u app --password app -d telemetry \
  -q "SELECT count() FROM events"                 # → 1
# после проверки: TRUNCATE TABLE events
```

nginx на этом этапе отдаёт 502/404 — это нормально, `index.php` ещё нет.

---

## Фаза 2 — каркас Symfony

Цель: приложение отвечает через nginx, консоль работает. Ещё без ClickHouse.

- [ ] 2.1 `app/composer.json` руками: `require` из SPEC.md §5, `autoload`
      `App\ → src/`, `php >= 8.3`
- [ ] 2.2 `docker compose exec php composer install`
- [ ] 2.3 `src/Kernel.php` с `MicroKernelTrait`; `config/bundles.php`
      (FrameworkBundle, TwigBundle); `config/packages/framework.yaml`
      (`secret`, `http_method_override: false`); `config/routes.yaml`
      (import атрибутов из `../src/Controller/`); `config/services.yaml`
      (autowire/autoconfigure, `App\` из `../src/`)
- [ ] 2.4 `public/index.php` и `bin/console` — стандартные однострочники
      symfony/runtime
- [ ] 2.5 `.env`: `APP_ENV=dev`, `APP_SECRET`, `CLICKHOUSE_URL=http://clickhouse:8123`,
      `CLICKHOUSE_USER=app`, `CLICKHOUSE_PASSWORD=app`, `CLICKHOUSE_DB=telemetry`
- [ ] 2.6 Времянка: `PageController::index()` на `GET /`, возвращает
      `new Response('ok')`
- [ ] 2.7 `templates/base.html.twig` — `<html>`, блок `content`, Chart.js с CDN,
      `<style>`-блок

**Проверка:**

```bash
curl -s http://localhost:8080/            # → ok
docker compose exec php bin/console      # → список команд без ошибок
```

---

## Фаза 3 — сервис ClickHouse и словарь полей

Цель: PHP умеет читать и писать в ClickHouse параметризованными запросами.

- [ ] 3.1 `src/Schema.php`: константы `DIMENSIONS` и `METRICS` — дословно списки
      из DATA_MODEL.md §2 (это единственное место со списками полей)
- [ ] 3.2 `src/Service/ClickHouse.php`: `select(string $sql, array $params = []): array`
      и `insertBatch(string $table, iterable $rows): void` по SPEC.md §5.
      Конфиг через конструктор из env (`services.yaml` → `%env(...)%`).
      При HTTP-статусе ≠ 200 бросать исключение с телом ответа ClickHouse
      (там внятный текст ошибки — без него отладка SQL мучительна)
- [ ] 3.3 Временно в `PageController::index()`: 
      `$ch->select('SELECT count() AS c FROM events')` и вывести число

**Проверка:**

```bash
curl -s http://localhost:8080/            # → число строк (0 после TRUNCATE)
# проверить параметры: select('SELECT {x:String} AS v', ['x' => "a'b"]) → a'b без ошибок
```

---

## Фаза 4 — генератор тестовых данных

Цель: `app:generate-events N` наполняет таблицу правдоподобными данными с аномалиями.

- [ ] 4.1 `src/Command/GenerateEventsCommand.php`: аргумент `count`,
      `ProgressBar`, пачки по 10 000 → `insertBatch`
- [ ] 4.2 Хелперы генерации в этом же классе (private-методы): `normal($mu, $sigma)`
      (Бокс–Мюллер), `pick(array)`, `weighted(array)`; детерминированные атрибуты
      машины/маршрута через `crc32()` — DATA_MODEL.md §2
- [ ] 4.3 Базовые распределения всех метрик — таблица из DATA_MODEL.md §2
- [ ] 4.4 Модификаторы аномалий A1–A4 — DATA_MODEL.md §3, применяются после
      базовой генерации
- [ ] 4.5 Прогнать `app:generate-events 100000`

**Проверка (SQL из DATA_MODEL.md §4):**

```bash
docker compose exec php bin/console app:generate-events 100000   # без ошибок, с прогрессом
```

```sql
SELECT event_type, count() FROM events GROUP BY event_type;
-- gps_ping ~60%, остальные по весам

SELECT last_fuel_station_id, round(avg(fuel_consumption_l100), 1) AS l100
FROM events GROUP BY last_fuel_station_id ORDER BY l100 DESC;
-- FS-07 заметно (≈+20%) выше остальных — если нет, аномалия A1 сделана неверно

SELECT driver_id, round(avg(harsh_events_cnt), 2) AS h
FROM events GROUP BY driver_id ORDER BY h DESC LIMIT 3;
-- D-13 первый с большим отрывом
```

Затем прогнать `app:generate-events 1000000` — должно уложиться в минуты.

---

## Фаза 5 — API списка и таблица событий

Цель: работает `/` с таблицей, фильтром и пагинацией.

- [ ] 5.1 `src/Controller/ApiController.php`: `GET /api/events`
      (limit/offset/фильтры → параметризованный SQL, ответ `{items, total}` —
      API.md §1) и `GET /api/events/{id}` (API.md §2, 404 если нет)
- [ ] 5.2 `PageController::index()` → нормальная страница: серверный рендеринг
      таблицы (контроллер сам ходит в ClickHouse, `/api/events` при этом остаётся
      для программного доступа), колонки и фильтр по `event_type` — API.md §4
- [ ] 5.3 `templates/events.html.twig`: таблица, `<select>` фильтра
      (значения — из `Schema`/контроллера), ссылки пагинации «новее/старше»,
      строка кликабельна → `/event/{event_id}`

**Проверка:**

```bash
curl -s 'http://localhost:8080/api/events?limit=2&event_type=refuel' | python3 -m json.tool
curl -s -o /dev/null -w '%{http_code}' 'http://localhost:8080/api/events/00000000-0000-0000-0000-000000000000'  # → 404
```

В браузере: `/` показывает события, фильтр и пагинация работают, клик по строке
открывает `/event/{id}` (пока 404 — страница будет в фазе 6).

---

## Фаза 6 — страница события и графики

Цель: ядро проекта — сетка сравнительных графиков.

- [ ] 6.1 `ApiController`: `GET /api/events/{id}/chart/{field}?similar_by=…` —
      API.md §3: валидация по белым спискам `Schema`, ветка dimension
      (countIf + GROUP BY) и ветка metric (widthBucket на 20 корзин),
      нормировка в проценты, дозаполнение пустых корзин нулями
- [ ] 6.2 `PageController::event()` на `GET /event/{id}`: карточка события +
      `similar_by` из query (дефолт `vehicle_id`, валидация по `Schema`)
- [ ] 6.3 `templates/event.html.twig`: `<dl>` с полями, `<select>` сходства
      (меняет query-параметр), CSS-grid с `<canvas>` на каждое поле, данные для
      JS — в data-атрибутах контейнера (API.md §4)
- [ ] 6.4 `public/app.js`: параллельные `fetch` по всем полям, Chart.js `bar`
      с двумя dataset'ами (`#f59e0b` similar / `#94a3b8` other), ось Y — проценты,
      подписи «similar (N=…)» / «other (N=…)»

**Проверка:**

```bash
EID=$(curl -s 'http://localhost:8080/api/events?limit=1' | python3 -c 'import sys,json;print(json.load(sys.stdin)["items"][0]["event_id"])')
curl -s "http://localhost:8080/api/events/$EID/chart/fuel_consumption_l100?similar_by=last_fuel_station_id" | python3 -m json.tool
# → 20 labels, similar_pct/other_pct по 20 чисел, суммы ≈ 100
curl -s -o /dev/null -w '%{http_code}' "http://localhost:8080/api/events/$EID/chart/evil?similar_by=vehicle_id"  # → 400
```

В браузере: страница события рендерит все графики без ошибок в консоли,
смена измерения в селекторе перерисовывает их.

---

## Фаза 7 — приёмка

Прогнать целиком сценарий из API.md §4 «Сценарий проверки UX» и критерии
приёмки SPEC.md §9:

- [ ] 7.1 Чистый старт с нуля: `docker compose down -v`, затем `up -d --build`,
      `composer install`, `app:generate-events 1000000` — только эти шаги
- [ ] 7.2 A1: событие с `FS-07`, сходство `last_fuel_station_id` → гистограмма
      `fuel_consumption_l100` у «похожих» сдвинута вправо
- [ ] 7.3 A2: событие водителя `D-13`, сходство `driver_id` → `harsh_events_cnt`
      и `speed_kmh` выше, среди «похожих» больше `harsh_braking`/`speeding`
- [ ] 7.4 A3: маршрут `R-04`, сходство `route_id` → `trip_duration_min` выше,
      `speed_kmh` ниже
- [ ] 7.5 A4: событие `daf` при `ambient_temp_c > 20`, сходство `vehicle_make` →
      `engine_temp_c` сдвинут вправо
- [ ] 7.6 Контрпример: событие с «обычной» заправкой (не FS-07) — распределения
      совпадают, ложных аномалий нет
- [ ] 7.7 Страница события на 1M строк открывается и дорисовывает все графики
      за несколько секунд

---

## Явно вне объёма (не делать)

Авторизация, тесты, CI, миграции, ORM, node-сборка, адаптивная вёрстка,
кэширование, реалтайм. Любое расширение объёма — только по запросу пользователя.
