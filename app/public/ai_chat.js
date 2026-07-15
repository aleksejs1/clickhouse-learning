/* Чат-панель ИИ (docs/AI_ASSISTANT.md §5).
   Отправляет prompt + history + текущий конфиг, применяет ответ в редактор. */

(function () {
    const panel = document.querySelector('.ai-chat');
    if (!panel) return;

    const kind = panel.dataset.kind; // 'report' | 'alert'
    const log = document.getElementById('ai-log');
    const input = document.getElementById('ai-prompt');
    const sendBtn = document.getElementById('ai-send');
    const spinner = document.getElementById('ai-spinner');
    const history = [];

    // как достать текущий конфиг и как применить ответ — зависит от редактора
    const getConfig = () => (kind === 'report'
        ? (window.reportEditorGetConfig ? window.reportEditorGetConfig() : null)
        : (window.alertEditorGetConfig ? window.alertEditorGetConfig() : null));
    const apply = (cfg) => (kind === 'report' ? window.reportEditorApply : window.alertEditorApply)(cfg);

    function addMessage(role, text) {
        const div = document.createElement('div');
        div.className = 'ai-msg ai-' + role;
        div.textContent = text;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    }

    async function send() {
        const prompt = input.value.trim();
        if (!prompt) return;
        input.value = '';
        addMessage('user', prompt);
        history.push({ role: 'user', content: prompt });
        spinner.hidden = false;
        sendBtn.disabled = true;

        try {
            const resp = await fetch('/api/ai/' + kind, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ prompt, history: history.slice(0, -1), current_config: getConfig() }),
            });
            const data = await resp.json().catch(() => ({}));
            if (!resp.ok) {
                const msg = data.error || 'Ошибка';
                const detail = data.errors ? '\n' + data.errors.join('\n') : '';
                addMessage('error', msg + detail);
                return;
            }
            addMessage('assistant', data.reply || '(готово)');
            history.push({ role: 'assistant', content: data.reply || '' });
            apply(data.config);
            // summary из ответа алерта — под канвасом (docs/PLAN_AI I3.3)
            if (kind === 'alert' && data.summary) {
                const box = document.getElementById('ae-summary');
                if (box) box.textContent = data.summary;
            }
        } catch (e) {
            addMessage('error', 'Сеть: ' + e.message);
        } finally {
            spinner.hidden = true;
            sendBtn.disabled = false;
        }
    }

    sendBtn.onclick = send;
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) send();
    });

    // автофокус, если пришли по «создать с ИИ»
    if (new URLSearchParams(location.search).get('ai') === '1') input.focus();
})();
