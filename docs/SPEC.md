# Техническое задание: Fleet Telemetry Explorer

## 1. Цель

Обучающий проект для изучения ClickHouse. Функционально — упрощённый аналог
«dimensions / BubbleUp» из Honeycomb для телеметрии логистического автотранспорта:

1. Таблица событий телеметрии с фильтрацией и пагинацией.
2. Страница события: все поля события + по одному графику на каждое поле.
   На каждом графике два ряда: **«похожие» события** (совпадает значение выбранного
   измерения сходства) и **«все остальные»**. Разница распределений визуально
   показывает аномалии.
3. Консольная команда генерации тестовых данных с заранее заложенными аномалиями
   (см. [DATA_MODEL.md](DATA_MODEL.md), раздел «Аномалии»).

Не входит в объём: авторизация, миграции, тесты, CI, деплой, реалтайм-обновления,
адаптивная вёрстка. Проект — учебный, критерий качества — минимальный объём кода
при рабочем результате.

## 2. Стек технологий

| Слой | Технология | Обоснование |
|---|---|---|
| БД | ClickHouse 24.x (официальный образ) | Предмет изучения. Единственное хранилище — других БД нет |
| Доступ к БД | HTTP-интерфейс ClickHouse (порт 8123) через `symfony/http-client` | Ноль сторонних драйверов; заодно показывает нативный HTTP API ClickHouse |
| Backend | PHP 8.3 + Symfony 7 (отдельные компоненты, **не skeleton**) | Только framework-bundle, twig, console, http-client — см. §5 |
| Frontend | Twig-шаблоны + Chart.js с CDN + нативный `fetch` | Ни node, ни сборки, ни SPA. Два шаблона и один небольшой JS-файл |
| Веб-сервер | nginx + php-fpm | Стандартная связка |
| Запуск | docker compose | Единственный способ сборки и запуска |

## 3. Структура репозитория

```
.
├── docker-compose.yml
├── docker/
│   ├── php/
│   │   └── Dockerfile              # php:8.3-fpm-alpine + composer
│   ├── nginx/
│   │   └── default.conf            # root /app/public, fastcgi → php:9000
│   └── clickhouse/
│       └── init/
│           └── 01_events.sql       # CREATE TABLE (выполняется при первом старте)
├── app/                            # Symfony-приложение
│   ├── bin/console
│   ├── composer.json
│   ├── .env                        # CLICKHOUSE_* переменные
│   ├── config/
│   │   ├── bundles.php
│   │   ├── services.yaml
│   │   ├── routes.yaml             # маршруты атрибутами, здесь только import
│   │   └── packages/framework.yaml
│   ├── public/
│   │   ├── index.php
│   │   └── app.js                  # отрисовка графиков (Chart.js)
│   ├── src/
│   │   ├── Kernel.php
│   │   ├── Schema.php              # словарь полей: DIMENSIONS и METRICS (константы)
│   │   ├── Service/
│   │   │   └── ClickHouse.php      # тонкая обёртка над HTTP-интерфейсом (~60 строк)
│   │   ├── Controller/
│   │   │   ├── PageController.php  # / и /event/{id} (Twig)
│   │   │   └── ApiController.php   # /api/* (JSON)
│   │   └── Command/
│   │       └── GenerateEventsCommand.php
│   └── templates/
│       ├── base.html.twig
│       ├── events.html.twig        # таблица событий
│       └── event.html.twig         # страница события с сеткой графиков
├── docs/
└── CLAUDE.md
```

## 4. Docker

`docker-compose.yml`, три сервиса:

```yaml
services:
  clickhouse:
    image: clickhouse/clickhouse-server:24.8
    ports: ["8123:8123"]              # HTTP-интерфейс наружу — для экспериментов с хоста
    environment:
      CLICKHOUSE_DB: telemetry
      CLICKHOUSE_USER: app
      CLICKHOUSE_PASSWORD: app
    volumes:
      - clickhouse-data:/var/lib/clickhouse
      - ./docker/clickhouse/init:/docker-entrypoint-initdb.d
    ulimits: { nofile: 262144 }

  php:
    build: docker/php
    volumes: ["./app:/app"]
    working_dir: /app
    depends_on: [clickhouse]

  nginx:
    image: nginx:1.27-alpine
    ports: ["8080:80"]
    volumes:
      - ./app:/app:ro
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on: [php]

volumes:
  clickhouse-data:
```

- `docker/php/Dockerfile`: `FROM php:8.3-fpm-alpine`, доставить `composer`
  (копией из образа `composer:2`) и расширение `intl` — этого достаточно,
  ClickHouse ходит по HTTP.
- SQL-файлы из `docker/clickhouse/init/` ClickHouse выполняет сам при первом
  запуске (стандартный механизм `/docker-entrypoint-initdb.d`).
- Подключение из PHP: `.env` → `CLICKHOUSE_URL=http://clickhouse:8123`,
  `CLICKHOUSE_USER=app`, `CLICKHOUSE_PASSWORD=app`, `CLICKHOUSE_DB=telemetry`.

## 5. Symfony без skeleton

`composer.json` создаётся вручную (`composer init` + `require`). Пакеты:

```
symfony/framework-bundle   symfony/runtime      symfony/console
symfony/twig-bundle        symfony/http-client  symfony/dotenv
symfony/yaml               symfony/uid
```

`symfony/flex` подключить можно (упрощает рецепты config/), но не обязательно.
Ничего лишнего: ни ORM, ни security, ни maker. Kernel — стандартный `MicroKernelTrait`.
Маршруты — PHP-атрибутами на контроллерах.

### Сервис `ClickHouse`

Один класс с двумя методами поверх `HttpClientInterface`:

- `select(string $sql, array $params = []): array` — POST на
  `{CLICKHOUSE_URL}/?database=...&default_format=JSON` + query-параметры вида
  `param_имя=значение` (серверные [параметры запросов ClickHouse](https://clickhouse.com/docs/en/interfaces/http#cli-queries-with-parameters),
  в SQL — плейсхолдеры `{имя:String}`; **никакой ручной интерполяции строк**).
  Возвращает `data` из JSON-ответа.
- `insertBatch(string $table, iterable $rows): void` — POST с телом в формате
  `JSONEachRow` (`INSERT INTO ... FORMAT JSONEachRow` в query-параметре `query`).

Аутентификация — заголовки `X-ClickHouse-User` / `X-ClickHouse-Key`.

### Словарь полей `Schema.php`

Единственный источник правды о полях события — два массива-константы:

- `Schema::DIMENSIONS` — категориальные поля (строки),
- `Schema::METRICS` — числовые поля.

Полный список — в [DATA_MODEL.md](DATA_MODEL.md). Контроллеры и шаблоны нигде
не перечисляют поля повторно — только через `Schema`. Значения из этих констант
можно подставлять в SQL как имена колонок (белый список), пользовательский ввод
имён полей всегда валидируется через `in_array` по этим константам.

## 6. Интерфейсы

Полное описание страниц, эндпоинтов и SQL-запросов — в [API.md](API.md). Кратко:

| Что | Путь |
|---|---|
| Таблица событий (HTML) | `GET /` |
| Страница события (HTML) | `GET /event/{id}` |
| Список событий (JSON) | `GET /api/events` |
| Одно событие (JSON) | `GET /api/events/{id}` |
| Данные одного графика (JSON) | `GET /api/events/{id}/chart/{field}?similar_by={dim}` |

## 7. Команда генерации данных

```bash
docker compose exec php bin/console app:generate-events <count>
```

- `count` — обязательный аргумент, число событий (например `100000` или `5000000`).
- Вставка пачками по 10 000 строк через `ClickHouse::insertBatch`.
- События раскиданы по последним 30 дням.
- Генератор закладывает 4 аномалии (см. [DATA_MODEL.md](DATA_MODEL.md)) — без них
  проект бессмыслен: именно их пользователь и должен «увидеть» на графиках.
- Команда идемпотентна в смысле «можно запускать много раз — данные добавляются».
  Для очистки: `TRUNCATE TABLE telemetry.events` вручную через clickhouse-client.

## 8. План работ

Детальный план по фазам с шагами и проверками — [PLAN.md](PLAN.md).
Кратко: Docker + ClickHouse → каркас Symfony → сервис ClickHouse и `Schema` →
генератор данных → API и таблица событий → страница события с графиками → приёмка.

## 9. Критерии приёмки

- `docker compose up -d --build` + `composer install` + генерация — единственные
  шаги для запуска с нуля; ничего не устанавливается на хост.
- На странице события рендерятся графики по **всем** полям из `Schema` (кроме
  `event_id`, `event_time` и самого поля сходства), «похожие»/«остальные» —
  двумя контрастными цветами, ряды нормированы в проценты.
- Все 4 заложенные аномалии реально находятся через UI за 1–2 клика.
- Генерация 1M событий проходит без ошибок и укладывается в минуты.
- В SQL нет конкатенации пользовательского ввода: значения — только через
  серверные параметры `{name:Type}`, имена полей — только из белого списка `Schema`.
