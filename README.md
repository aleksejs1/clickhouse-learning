# Fleet Telemetry Explorer

Обучающий проект для знакомства с ClickHouse. Идея взята из Honeycomb (BubbleUp):
есть поток событий телеметрии логистического автотранспорта, по клику на любое
событие открывается страница с десятками графиков — по одному на каждое поле.
На каждом графике два цвета: «похожие» события (с тем же значением выбранного
измерения, что у открытого события) и «все остальные». Расхождение распределений
сразу показывает аномалию — например, что все машины, заправлявшиеся на заправке
FS-07, после этого имеют повышенный средний расход топлива.

**Без авторизации, без прода, минимум ручного кода.** Всё запускается только через Docker.

## Быстрый старт

```bash
docker compose up -d --build          # ClickHouse + PHP + nginx
docker compose exec php composer install
docker compose exec php bin/console app:generate-events 100000
```

Открыть http://localhost:8081 — таблица событий. Клик по строке → страница события с графиками.
В шапке: **Аномалии** (автопоиск), **Репорты** (конструктор бордов),
**Алерты** (конструктор графов в стиле n8n, только конфигурация).

Загрузить эталонные конфиги (опционально):

```bash
for f in docs/examples/reports/*.json; do curl -s -X POST localhost:8081/api/reports -d @$f; done
for f in docs/examples/alerts/*.json;  do curl -s -X POST localhost:8081/api/alerts  -d @$f; done
```

Консоль ClickHouse для экспериментов:

```bash
docker compose exec clickhouse clickhouse-client -u app --password app -d telemetry
```

## Документация

| Файл | Содержание |
|---|---|
| [docs/SPEC.md](docs/SPEC.md) | Техническое задание: стек, архитектура, структура проекта и докеров |
| [docs/PLAN.md](docs/PLAN.md) | План имплементации ядра: 8 фаз с шагами-чекбоксами и проверками |
| [docs/PLAN_REPORTS.md](docs/PLAN_REPORTS.md), [PLAN_ALERTS.md](docs/PLAN_ALERTS.md), [PLAN_AI.md](docs/PLAN_AI.md) | Планы имплементации репортов, алертов и ИИ-ассистента |
| [docs/DATA_MODEL.md](docs/DATA_MODEL.md) | Схема таблицы ClickHouse, словарь полей, генератор данных и заложенные аномалии |
| [docs/API.md](docs/API.md) | HTTP API, страницы веб-интерфейса, готовые SQL-рецепты для графиков |
| [docs/REPORTS.md](docs/REPORTS.md) | ТЗ: конфигуратор репортов (борды из виджетов, декларативный JSON) |
| [docs/ALERTS.md](docs/ALERTS.md) | ТЗ: конфигуратор алертов (граф узлов в стиле n8n, без исполнения) |
| [docs/AI_ASSISTANT.md](docs/AI_ASSISTANT.md) | ТЗ: чат-бот, генерирующий конфиги репортов и алертов (Anthropic API) |
| [docs/BACKLOG.md](docs/BACKLOG.md) | Бэклог идей: что ещё попробовать |
| [CLAUDE.md](CLAUDE.md) | Шпаргалка по командам и ключевым решениям |

## Что здесь можно «пощупать» в ClickHouse

- HTTP-интерфейс (порт 8123) — запросы обычным HTTP-клиентом, без драйверов;
- широкая денормализованная таблица событий на движке `MergeTree`;
- `LowCardinality(String)` для измерений;
- агрегации `countIf`, `widthBucket`, `quantile` на миллионах строк за миллисекунды;
- форматы `JSON` / `JSONEachRow` для чтения и пакетной вставки;
- `ReplacingMergeTree` + `FINAL` для изменяемых данных (конфиги репортов и
  алертов): обновление — INSERT новой версии, удаление — soft delete.
