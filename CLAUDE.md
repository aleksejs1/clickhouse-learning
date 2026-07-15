# Fleet Telemetry Explorer

Обучающий проект для изучения ClickHouse: Honeycomb-подобный «BubbleUp» по событиям
телеметрии грузового автотранспорта. ТЗ полное и обязательное к прочтению перед работой:

- **docs/SPEC.md** — стек, структура репозитория и докеров, критерии приёмки
- **docs/PLAN.md** — план имплементации по фазам; работать строго по нему,
  отмечать чекбоксы выполненных шагов и выполнять проверки в конце каждой фазы
- **docs/REPORTS.md** — ТЗ конфигуратора репортов (борды из виджетов)
- **docs/ALERTS.md** — ТЗ конфигуратора алертов (граф узлов, только конфиг)
- **docs/AI_ASSISTANT.md** — ТЗ ИИ-ассистента (текст → конфиг)
- **docs/PLAN_REPORTS.md → PLAN_ALERTS.md → PLAN_AI.md** — планы имплементации
  второй очереди, выполнять в этом порядке (алерты требуют фазу R0, ИИ —
  валидаторы и примеры из первых двух); правила работы те же, что для PLAN.md
- **docs/DATA_MODEL.md** — схема `telemetry.events`, словарь полей, генератор, 4 заложенные аномалии
- **docs/API.md** — эндпоинты, SQL-рецепты графиков, страницы UI

## Команды

```bash
docker compose up -d --build                                  # запуск всего
docker compose exec php composer install                      # зависимости
docker compose exec php bin/console app:generate-events 100000  # тестовые данные
docker compose exec clickhouse clickhouse-client -u app --password app -d telemetry
curl 'http://localhost:8123/ping'                             # ClickHouse жив?
```

UI: http://localhost:8081. Всё выполняется только внутри контейнеров — на хосте
нет ни PHP, ни composer.

## Ключевые решения (не менять без запроса пользователя)

- **Только ClickHouse**, других БД нет. Доступ — через HTTP-интерфейс (порт 8123)
  и `symfony/http-client`; сторонние ClickHouse-драйверы не подключать.
- **Symfony 7 из отдельных компонентов**, не skeleton: framework-bundle, runtime,
  console, twig-bundle, http-client, dotenv, yaml, uid. Не добавлять ORM,
  security, maker и т.п.
- **Фронт без сборки**: Twig + Chart.js с CDN + один `public/app.js`. Никакого
  node/npm/Webpack/AssetMapper.
- **Никакой авторизации**, никаких тестов/CI — учебный проект, цель — минимум кода.
- Список полей события существует в коде ровно один раз — константы
  `Schema::DIMENSIONS` / `Schema::METRICS` (`app/src/Schema.php`).
- **Репорты и алерты — только декларативные JSON-конфиги** (форматы в
  REPORTS.md §4 и ALERTS.md §3). SQL из конфига собирает сервер по белым
  спискам; конфиг с SQL/произвольными выражениями — запрещён by design.
- Конфиги хранятся в ClickHouse: `report_configs` / `alert_configs` на
  ReplacingMergeTree(updated_at); чтение через `FINAL WHERE is_deleted = 0`,
  обновление = INSERT новой версии, удаление = soft delete.
- Редактор алертов — Drawflow с CDN (единственная разрешённая JS-библиотека
  кроме Chart.js и Leaflet); хранится семантический JSON, не экспорт Drawflow.
- ИИ-ассистент: официальный PHP SDK `anthropic-ai/sdk`, модель
  `claude-opus-4-8`, structured outputs (`outputConfig` json_schema),
  `thinking: adaptive`, промпт-кэш на system. Ключ `ANTHROPIC_API_KEY` в
  `.env.local` опционален — без него ИИ-фичи скрыты, проект работает.
  Конфиг от ИИ проходит те же валидаторы, что и ввод пользователя.

## Правила по SQL

- Значения — только через серверные параметры ClickHouse: `{name:String}` в SQL
  + query-параметр `param_name`. Конкатенация значений в SQL запрещена.
- Имена колонок в динамических запросах — только после проверки по белому
  списку из `Schema`.
- Схема таблицы меняется только синхронно в `docker/clickhouse/init/01_events.sql`,
  `Schema.php` и `docs/DATA_MODEL.md`. Init-SQL применяется лишь при первом старте
  тома: после изменения схемы — `docker compose down -v` и заново.
- Почасовые агрегаты для таймлайна — MV `events_by_hour`
  (`docker/clickhouse/init/02_events_by_hour.sql`). MV видит только новые
  вставки: если создаёте его поверх существующих данных — нужен backfill
  (запрос в комментарии DDL-файла). При чтении обязательны `GROUP BY` + `sum()`.

## Определение «похожих» событий (ядро проекта)

На странице события выбирается измерение сходства `similar_by` (дефолт `vehicle_id`).
«Похожие» = события, у которых значение `similar_by` совпадает со значением у
открытого события; «остальные» = все прочие. Каждый график — распределение одного
поля в этих двух группах, нормированное в проценты. Аномалии заложены генератором
(FS-07 → расход ×1.2 и др., см. DATA_MODEL.md §3) и обязаны быть видны в UI.
