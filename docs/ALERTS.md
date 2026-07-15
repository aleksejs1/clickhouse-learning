# ТЗ: Конфигуратор алертов

## 1. Цель и границы

Визуальный конструктор алертов в стиле n8n: узлы на канвасе, соединённые
рёбрами, — «триггер → условия/обработка → действия». **Только конфигуратор:
алерты не исполняются**, потому что в проекте нет потока данных. Результат
работы — валидный сохранённый граф, который в реальной системе исполнял бы
воркер.

Как и репорты, алерт — **декларативный JSON-документ**, одинаковый для
UI-редактора, API и ИИ-ассистента ([AI_ASSISTANT.md](AI_ASSISTANT.md)).

## 2. Стек

- **Drawflow** (canvas-редактор узлов, ванильный JS, MIT) — с CDN:
  `https://cdn.jsdelivr.net/npm/drawflow@0.0.60/dist/drawflow.min.js` + его CSS.
  Выбран как самый простой из живых node-editor'ов без сборки; альтернативы
  (Rete.js, LiteGraph) тяжелее.
- Хранение — таблица `alert_configs` (ReplacingMergeTree, DDL и правила
  чтения/записи — REPORTS.md §3).
- Каталог узлов — PHP-константы (`App\Alerts\NodeCatalog`), единый источник
  для палитры UI, валидатора и промпта ИИ.

## 3. Модель графа

Сохраняем **семантический формат**, а не сырой экспорт Drawflow (он привязан к
библиотеке и замусорен HTML-ом). Конвертация в/из drawflow — на клиенте.

```json
{
  "version": 1,
  "name": "Перегрев двигателя",
  "description": "Письмо механику при перегреве",
  "enabled": true,
  "nodes": [
    {"id": "n1", "type": "metric_threshold", "position": {"x": 60, "y": 120},
     "params": {"metric": "engine_temp_c", "agg": "avg", "op": ">", "value": 105,
                "window_minutes": 15, "group_by": "vehicle_id"}},
    {"id": "n2", "type": "dedup", "position": {"x": 360, "y": 120},
     "params": {"cooldown_minutes": 60}},
    {"id": "n3", "type": "notify_email", "position": {"x": 660, "y": 120},
     "params": {"to": "mechanic@example.com",
                "subject": "Перегрев {{vehicle_id}}",
                "body": "Средняя температура {{value}}°C за 15 минут"}}
  ],
  "edges": [
    {"from": "n1", "to": "n2"},
    {"from": "n2", "to": "n3"}
  ]
}
```

- `id` узла: `^[a-z][a-z0-9_]{0,20}$`, уникален.
- Ребро по умолчанию из единственного выхода; у узла `condition` два выхода —
  тогда `{"from": "n2", "from_port": "true", "to": "n3"}` (порт `true`/`false`).
- В текстовых параметрах действий допустимы плейсхолдеры `{{...}}`:
  `{{vehicle_id}}`, `{{driver_id}}`, `{{metric}}`, `{{value}}`, `{{threshold}}`,
  `{{time}}` — подставлял бы исполнитель; конфигуратор их только упоминает
  в подсказках.

## 4. Каталог узлов

Формат описания одного типа узла в `NodeCatalog` (он же отдаётся API):

```json
{
  "type": "metric_threshold",
  "category": "trigger",
  "label": "Порог метрики",
  "inputs": 0, "outputs": 1,
  "params": [
    {"name": "metric", "type": "metric_field", "required": true},
    {"name": "agg", "type": "enum", "values": ["avg", "min", "max", "sum", "count"], "default": "avg"},
    {"name": "op", "type": "enum", "values": [">", ">=", "<", "<=" ], "required": true},
    {"name": "value", "type": "number", "required": true},
    {"name": "window_minutes", "type": "number", "default": 15},
    {"name": "group_by", "type": "dimension_field", "default": "vehicle_id"}
  ]
}
```

Типы параметров: `string`, `number`, `bool`, `enum`, `cron`,
`dimension_field` / `metric_field` (валидируются по `Schema`),
`filters` (массив фильтров в формате REPORTS.md §4), `template` (строка с `{{}}`).

### Триггеры (`inputs: 0`)

| type | Параметры | Доменный смысл |
|---|---|---|
| `metric_threshold` | metric, agg, op, value, window_minutes, group_by | перегрев, превышение скорости, давление в шинах |
| `anomaly_deviation` | dimension, metric, sigma_threshold, window_hours | «группа отклонилась от парка на N σ» (логика страницы /anomalies) |
| `fuel_drop` | drop_pct, window_minutes | слив топлива: падение fuel_level_pct вне заправки |
| `no_data` | group_by, silence_minutes | датчик молчит / машина пропала |
| `geofence` | lat, lon, radius_km, direction (enter/exit), group_by | выход из зоны, прибытие на объект |
| `odometer_milestone` | every_km | ТО по пробегу |
| `schedule` | cron | периодическая проверка/отчёт |

### Условия и обработка (`inputs: 1`)

| type | Параметры | Выходы | Смысл |
|---|---|---|---|
| `filter` | filters (как в репортах) | 1 | пропускать только грузовики, только регион X |
| `condition` | field, op, value | 2 (`true`/`false`) | ветвление: критично → SMS, иначе → email |
| `time_window` | days_of_week[], from_time, to_time | 1 | только рабочие часы |
| `dedup` | cooldown_minutes | 1 | не чаще раза в час на группу |
| `severity` | level (info/warning/critical) | 1 | пометить важность (используют действия) |
| `digest` | period_minutes | 1 | копить события и отдавать пачкой |

### Действия (`outputs: 0`)

| type | Параметры |
|---|---|
| `notify_email` | to, subject (template), body (template) |
| `notify_sms` | phone, text (template) |
| `notify_telegram` | chat_id, text (template) |
| `webhook` | url, method (enum GET/POST), payload (template) |
| `create_ticket` | queue, title (template), description (template) |
| `escalate` | after_minutes, to — «если не подтверждено за N минут, письмо руководителю» |

## 5. Валидация (`App\Alerts\AlertValidator`)

`POST /api/alerts/validate` (и та же проверка при сохранении):

1. все `type` существуют в каталоге; `params` соответствуют схеме узла
   (типы, required, enum, поля по `Schema`);
2. ≥ 1 триггер; у триггеров нет входящих рёбер; у действий нет исходящих;
3. каждый триггер имеет путь хотя бы к одному действию; нет узлов-сирот;
4. граф ацикличен; `from_port` указан только у узлов с 2 выходами;
5. рёбра ссылаются на существующие узлы.

Ответ:

```json
{
  "valid": false,
  "errors": [{"node": "n2", "message": "params.cooldown_minutes: должно быть числом"}],
  "summary": "Когда средняя engine_temp_c по vehicle_id за 15 мин > 105 → не чаще раза в 60 мин → письмо на mechanic@example.com"
}
```

`summary` — человекочитаемый пересказ графа, который сервер собирает обходом
от триггеров к действиям. Он нужен трижды: пользователю для самопроверки,
списку алертов как описание, и ИИ-петле как обратная связь.

## 6. API

| Метод и путь | Что делает |
|---|---|
| `GET /api/alerts` | список: `[{id, name, enabled, summary, updated_at}]` |
| `POST /api/alerts` / `GET|PUT|DELETE /api/alerts/{id}` | CRUD как у репортов (422 + ошибки валидатора) |
| `GET /api/alert-nodes` | каталог узлов целиком (палитра UI и промпт ИИ) |
| `POST /api/alerts/validate` | проверка без сохранения (см. §5) |

## 7. Web UI

- **`GET /alerts`** — список: имя, вкл/выкл (переключатель пишет `enabled`),
  summary, дата; «создать», «создать с ИИ». Ссылка в шапке сайта.
- **`GET /alert/{id}/edit`** — редактор:
  - слева **палитра**: узлы по категориям (Триггеры / Условия / Действия),
    строится из `/api/alert-nodes`; перетаскивание на канвас (drag → `addNode`);
  - центр — **канвас Drawflow**: соединение портов мышью, перемещение, удаление;
  - клик по узлу → **панель свойств** справа: форма по схеме `params` узла
    (генерируется из каталога: enum → select, `*_field` → select из Schema,
    template → textarea с подсказкой о плейсхолдерах);
  - кнопки: «Проверить» (POST validate → ошибки списком + summary под канвасом),
    «Сохранить» (валидация обязательна), «JSON» (textarea с семантическим
    конфигом, кнопка «применить»);
  - чат-панель ИИ (AI_ASSISTANT.md): применённый от ИИ конфиг раскладывается
    на канвасе (позиции узлов ИИ тоже генерирует).

Конвертер `semantic JSON ↔ drawflow` — модуль в app.js (~80 строк):
`toDrawflow(config)` при загрузке, `fromDrawflow(editor.export())` при
сохранении/проверке.

## 8. Примеры алертов (эталонные конфиги)

`docs/examples/alerts/*.json` — они же few-shot для ИИ:

1. **Перегрев** (из §3): threshold → dedup → email.
2. **Слив топлива**: fuel_drop → filter (только truck/tanker) → severity
   critical → telegram + create_ticket (одно ребро в два действия).
3. **Геозона в нерабочее время**: geofence (exit) → time_window (ночь) →
   condition (cargo_weight_kg > 0: true → SMS охране, false → email диспетчеру).
4. **Молчащий датчик с эскалацией**: no_data 30 мин → dedup → email механику →
   escalate (60 мин, руководителю).

## 9. Критерии приёмки

- Все 4 эталонных алерта собираются мышью в редакторе, проходят валидацию,
  сохраняются и открываются заново с теми же позициями узлов.
- Валидатор ловит: граф без действия, цикл, триггер с входящим ребром, неверный
  enum, чужое имя поля.
- `summary` для примера №3 корректно описывает обе ветки condition.
- Отключение алерта переключателем в списке сохраняется (новая версия строки).
