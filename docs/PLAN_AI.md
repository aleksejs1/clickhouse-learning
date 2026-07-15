# План имплементации: ИИ-ассистент

ТЗ — [AI_ASSISTANT.md](AI_ASSISTANT.md). Выполняется **последним**: нужны
валидаторы и эталонные примеры из [PLAN_REPORTS.md](PLAN_REPORTS.md) (фазы
R1–R4) и [PLAN_ALERTS.md](PLAN_ALERTS.md) (фазы A0–A1, A5.1) — примеры служат
few-shot'ами, валидаторы замыкают петлю ретрая.

Для проверок нужен действующий `ANTHROPIC_API_KEY` (платно; порядок цен —
центы за запрос). Все фазы, кроме I0, без ключа не проверяются.

**Статус: проверено end-to-end с реальным ключом.** Работают генерация репортов
и алертов по свободному тексту, мультивиджетный дашборд, уточнение с
`current_config` (меняет только запрошенное), промпт-кэш (второй запрос —
`cache_read>0`), браузерный round-trip чата (конфиг применяется в оба
редактора). По ходу приёмки исправлено три вещи: (1) доустановлен PSR-17
`nyholm/psr7` — без него SDK не шлёт HTTP; (2) таймаут клиента поднят до 180с
(adaptive thinking дольше 60с по умолчанию); (3) схемы structured outputs —
`params` узлов алерта как JSON-строка (иначе >40 опциональных полей раздувают
грамматику), сервер её раскодирует; заодно пойман баг `foreach ($x ?? [] as &$n)`
(копия вместо ссылки).

---

## Фаза I0 — SDK и деградация без ключа

- [x] I0.1 `docker compose exec php composer require anthropic-ai/sdk`
- [x] I0.2 `.env`: `ANTHROPIC_API_KEY=` (пустой по умолчанию); ключ кладётся в
      `app/.env.local` (в .gitignore); параметр в `services.yaml`
- [x] I0.3 Маршруты-заглушки `POST /api/ai/report`, `POST /api/ai/alert` → 503
      `{error: "AI не настроен: задайте ANTHROPIC_API_KEY"}` если ключ пуст
- [x] I0.4 Флаг `ai_enabled` прокинут во все шаблоны (глобальная переменная Twig
      через twig.yaml globals или контроллеры)

**Проверка:**

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost:8081/api/ai/report -d '{"prompt":"x"}'   # → 503 без ключа
docker compose exec php php -r 'require "vendor/autoload.php"; var_dump(class_exists(Anthropic\Client::class));'  # → true
```

---

## Фаза I1 — промпты

- [x] I1.1 `app/prompts/report.md`, `app/prompts/alert.md` — статичные части
      system prompt: роль, правила, дефолты (AI_ASSISTANT.md §6)
- [x] I1.2 `app/src/Ai/PromptBuilder.php` — сборка полного system prompt в
      **стабильном порядке**: статичный файл → словарь данных (генерируется из
      `Schema` + значения измерений из констант генератора) → описание формата
      (из `ReportSchema` / дампа `NodeCatalog`) → few-shot из `docs/examples/`
- [x] I1.3 Временная консольная команда `app:ai-prompt report|alert` — печатает
      готовый system prompt (останется как отладочный инструмент)

**Проверка:**

```bash
docker compose exec php bin/console app:ai-prompt report | wc -c    # порядка 10-30 КБ
docker compose exec php bin/console app:ai-prompt alert | grep -c 'metric_threshold\|notify_email'  # ≥2 — каталог узлов попал
# повторный вызов даёт байт-в-байт тот же текст (иначе промпт-кэш не работает): diff <(cmd) <(cmd)
```

---

## Фаза I2 — AiConfigurator и эндпоинты

- [x] I2.1 JSON-схемы ответа `{reply, config}` для structured outputs: report
      (виджеты с enum'ами fn/op/viz) и alert (nodes/edges с enum типов узлов);
      настолько строгие, насколько позволяет structured outputs
- [x] I2.2 `app/src/Ai/AiConfigurator.php`: вызов
      `messages->create(model: claude-opus-4-8, maxTokens: 16000,
      thinking: adaptive, system: [+cacheControl ephemeral], messages,
      outputConfig: json_schema)`; таймаут клиента ≥120с
- [x] I2.3 Петля валидации: серверный валидатор → при ошибках один повторный
      вызов с текстом ошибок → 422 если снова невалиден
- [x] I2.4 Эндпоинты `POST /api/ai/report` и `/api/ai/alert` по AI_ASSISTANT.md §4
      (history, current_config; alert дополнительно возвращает summary);
      ошибки Anthropic API → 502

**Проверка:**

```bash
curl -s -X POST http://localhost:8081/api/ai/report -H 'Content-Type: application/json' \
  -d '{"prompt": "средний расход топлива по заправкам за последнюю неделю", "history": []}' | python3 -m json.tool
# → config: bar, avg(fuel_consumption_l100), group_by last_fuel_station_id, last_hours 168; reply по-русски

curl -s -X POST http://localhost:8081/api/ai/alert -H 'Content-Type: application/json' \
  -d '{"prompt": "если машина перегрелась выше 105 градусов — письмо механику, не чаще раза в час", "history": []}' | python3 -m json.tool
# → граф threshold → dedup → email; valid; summary осмыслен

# уточнение с current_config: «сделай по дням, а не по часам» меняет только time_bucket
# второй запрос с тем же system должен показать cache_read_input_tokens > 0 (залогировать usage)
```

- [x] I2.5 Логировать `usage` (input/output/cache_read) каждого вызова в
      error_log — и для отладки, и как учебная витрина промпт-кэша

---

## Фаза I3 — чат-панель UI

- [x] I3.1 Общий JS-компонент чата (лента, ввод, спиннер, ошибки) в app.js;
      Twig-инклюд `_ai_chat.html.twig`, рендерится только при `ai_enabled`
- [x] I3.2 Встроить в `/report/{id}/edit`: ответный config применяется к
      редактору (форма+предпросмотр перечитывают конфиг), history и
      current_config уходят с каждым запросом
- [x] I3.3 Встроить в `/alert/{id}/edit`: config раскладывается на канвас
      (`toDrawflow`), summary из ответа показывается под канвасом
- [x] I3.4 Кнопки «Создать с ИИ» на `/reports` и `/alerts` → редактор нового
      объекта с фокусом в поле чата
- [x] I3.5 Применённый конфиг не сохраняется сам — только по «Сохранить»

**Проверка (в браузере):** сценарий «создать с ИИ» для репорта и для алерта;
диалог из двух шагов (создать → уточнить); закрыть без сохранения → объект не
появился в списке.

---

## Фаза I4 — приёмка

Критерии AI_ASSISTANT.md §8, все шесть, особо:

- [x] I4.1 Мультивиджетный запрос («дашборд для механика…») → ≥3 валидных виджета
- [x] I4.2 Негатив: сломать JSON-схему ответа (временно) → ретрай → 422 в чате,
      редактор жив
- [x] I4.3 Без ключа: панели скрыты, 503, всё остальное работает
- [x] I4.4 Зафиксировать в README: фича опциональна и платна; примерная
      стоимость запроса по данным usage из I2.5
