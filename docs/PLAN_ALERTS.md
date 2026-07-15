# План имплементации: конфигуратор алертов

ТЗ — [ALERTS.md](ALERTS.md). Выполняется **после фазы R0** плана
[PLAN_REPORTS.md](PLAN_REPORTS.md) (таблица `alert_configs` и `ConfigStore`
создаются там). Остальные фазы репортов для алертов не нужны — планы
независимы, но общий формат `filters` у узла `filter` переиспользует
валидацию фильтров из `ReportSchema`/`ReportValidator` — если репорты ещё не
делались, вынести проверку одного фильтра в общий хелпер.

---

## Фаза A0 — каталог узлов

- [x] A0.1 `app/src/Alerts/NodeCatalog.php` — все 19 типов узлов из ALERTS.md §4
      константой: category, label, inputs/outputs, params со схемами
      (type/required/default/values)
- [x] A0.2 `GET /api/alert-nodes` — каталог как JSON (для палитры UI и промпта ИИ);
      для params типа `dimension_field`/`metric_field` — сразу отдать допустимые
      значения из `Schema`

**Проверка:**

```bash
curl -s http://localhost:8081/api/alert-nodes | python3 -c '
import sys, json
d = json.load(sys.stdin)
cats = {}
for n in d: cats.setdefault(n["category"], []).append(n["type"])
for c, ts in cats.items(): print(c, len(ts), ts)'
# → trigger 7, condition 6, action 6; у metric_threshold param metric содержит values из Schema::METRICS
```

---

## Фаза A1 — валидатор и summary

- [x] A1.1 `app/src/Alerts/AlertValidator.php` — правила ALERTS.md §5:
      params по схемам каталога, структура графа (≥1 триггер, пути к действиям,
      ацикличность, порты, сироты)
- [x] A1.2 `app/src/Alerts/AlertSummary.php` — человекочитаемый пересказ:
      обход от каждого триггера к действиям, включая обе ветки `condition`
- [x] A1.3 `POST /api/alerts/validate` → `{valid, errors, summary}`

**Проверка:**

```bash
# валидный пример «перегрев» (JSON из ALERTS.md §3):
curl -s -X POST http://localhost:8081/api/alerts/validate -d @docs/examples/alerts/overheat.json | python3 -m json.tool
# → valid: true, summary содержит «105», «60 мин», «mechanic@example.com»

# негативные (по одному curl на каждый): без действия; цикл n1→n2→n1;
# ребро в триггер; op: "===" (не из enum); metric: "нет_такого" → valid: false + внятные errors
```

---

## Фаза A2 — CRUD API

- [x] A2.1 `GET/POST /api/alerts`, `GET/PUT/DELETE /api/alerts/{id}` поверх
      `ConfigStore('alert_configs')`; сохранение только валидного графа (422);
      в списке — `enabled` и `summary` (пересчитывается при чтении)

**Проверка:** CRUD-цикл curl-ами как в фазе R3; PUT с изменённым только
`enabled` проходит (это путь переключателя из списка).

---

## Фаза A3 — список алертов

- [x] A3.1 `GET /alerts`: таблица (имя, summary, переключатель enabled, дата,
      редактировать/удалить), кнопки «создать» и «создать с ИИ» (вторая —
      заглушка до PLAN_AI); ссылка «Алерты» в шапке
- [x] A3.2 Переключатель шлёт PUT и не перезагружает страницу

**Проверка:** загрузить пример через API → виден в списке с summary;
переключить enabled → перезагрузка страницы сохраняет состояние.

---

## Фаза A4 — редактор на Drawflow

- [x] A4.1 Подключить Drawflow с CDN (JS+CSS) только на странице редактора
      (`{% block head %}`); `GET /alert/{id}/edit`, `GET /alert/new`
- [x] A4.2 Палитра слева из `/api/alert-nodes` по категориям; drag&drop на
      канвас создаёт узел (`editor.addNode`) с дефолтными params
- [x] A4.3 Конвертер `semantic JSON ↔ drawflow` в app.js: `toDrawflow(config)`
      при загрузке (позиции из `position`), `fromDrawflow(editor.export())`
      при сохранении/проверке
- [x] A4.4 Клик по узлу → панель свойств справа: форма генерируется из схемы
      params каталога (enum → select, `*_field` → select из Schema,
      template → textarea с подсказкой про `{{...}}`); изменения пишутся в узел
- [x] A4.5 Кнопки «Проверить» (ошибки списком + summary под канвасом),
      «Сохранить» (сначала валидация), вкладка «JSON» с «применить»
- [x] A4.6 У `condition` два выходных порта, подписанных true/false;
      конвертер заполняет `from_port`

**Проверка (в браузере):** собрать мышью пример №1 «перегрев» с нуля:
палитра → 3 узла → соединить → заполнить params → «Проверить» (summary
совпадает с ожидаемым) → «Сохранить» → перезагрузить редактор: узлы на тех же
местах, параметры на месте. Затем собрать пример №3 с ветвлением condition.

---

## Фаза A5 — примеры и приёмка

- [x] A5.1 `docs/examples/alerts/*.json` — 4 эталона из ALERTS.md §8, все
      проходят validate; загрузить через API
- [x] A5.2 Критерии ALERTS.md §9 целиком (включая негативные проверки валидатора)
- [x] A5.3 Чистый старт с нуля: редактор работает на пустой базе
