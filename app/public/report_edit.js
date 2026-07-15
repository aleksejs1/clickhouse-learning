/* Редактор репорта: формы генерируются из ReportSchema (docs/REPORTS.md §7).
   Зависит от renderReport() из reports.js (предпросмотр). */

(function () {
    const root = document.getElementById('report-editor');
    if (!root) return;

    const SCHEMA = JSON.parse(root.dataset.schema);
    const reportId = root.dataset.id || null;
    let config = JSON.parse(root.dataset.config);
    let selected = config.widgets.length ? 0 : -1;

    const $ = (id) => document.getElementById(id);
    const el = (tag, props = {}, children = []) => {
        const n = Object.assign(document.createElement(tag), props);
        for (const c of children) n.append(c);
        return n;
    };
    const option = (v, label, sel) => el('option', { value: v, textContent: label ?? v, selected: sel === v });

    $('re-name').value = config.name || '';
    $('re-desc').value = config.description || '';
    $('re-name').oninput = () => { config.name = $('re-name').value; };
    $('re-desc').oninput = () => { config.description = $('re-desc').value; };

    /* --- список виджетов --- */
    function renderWidgetList() {
        const ul = $('re-widget-list');
        ul.innerHTML = '';
        config.widgets.forEach((w, i) => {
            const li = el('li', { className: i === selected ? 'sel' : '' });
            li.append(el('span', { textContent: w.title || '(без названия)' }));
            li.onclick = (e) => { if (e.target === li || e.target.tagName === 'SPAN') { selected = i; renderAll(); } };
            const up = el('button', { textContent: '↑', title: 'вверх' });
            up.onclick = () => moveWidget(i, -1);
            const down = el('button', { textContent: '↓', title: 'вниз' });
            down.onclick = () => moveWidget(i, 1);
            const rm = el('button', { textContent: '✕', title: 'удалить' });
            rm.onclick = () => { config.widgets.splice(i, 1); selected = Math.min(selected, config.widgets.length - 1); renderAll(); };
            li.append(up, down, rm);
            ul.append(li);
        });
    }

    function moveWidget(i, dir) {
        const j = i + dir;
        if (j < 0 || j >= config.widgets.length) return;
        [config.widgets[i], config.widgets[j]] = [config.widgets[j], config.widgets[i]];
        if (selected === i) selected = j; else if (selected === j) selected = i;
        renderAll();
    }

    $('re-add-widget').onclick = () => {
        config.widgets.push({
            title: 'Новый виджет', width: 1,
            query: { time_range: { last_hours: 168 }, filters: [], group_by: [],
                aggregations: [{ fn: 'count', alias: 'cnt' }] },
            viz: { type: 'table' },
        });
        selected = config.widgets.length - 1;
        renderAll();
    };

    /* --- форма выбранного виджета --- */
    function renderForm() {
        const box = $('re-form');
        box.innerHTML = '';
        if (selected < 0) { box.append(el('p', { className: 'muted', textContent: 'Добавьте виджет' })); return; }
        const w = config.widgets[selected];
        const q = w.query;

        box.append(field('Заголовок', textInput(w.title, (v) => { w.title = v; renderWidgetList(); })));
        box.append(field('Ширina', select(['1', '2', '3'], String(w.width), (v) => { w.width = +v; preview(); })));
        box.append(field('Визуализация', select(Object.keys(SCHEMA.viz), w.viz.type, (v) => { w.viz.type = v; preview(); })));

        // период
        const hours = (q.time_range && q.time_range.last_hours) || 168;
        box.append(field('Период, часов', numInput(hours, (v) => { q.time_range = { last_hours: v }; preview(); })));

        // time_bucket
        box.append(field('Бакет времени', select(['', ...SCHEMA.time_buckets], q.time_bucket || '',
            (v) => { q.time_bucket = v || null; preview(); }, 'нет')));

        // group_by (до 2)
        const gb = el('div', { className: 're-group' }, [el('b', { textContent: 'Группировка (0..2 измерения)' })]);
        (q.group_by || []).forEach((g, i) => {
            const row = el('div', { className: 're-row' });
            row.append(select(SCHEMA.dimensions, g, (v) => { q.group_by[i] = v; preview(); }));
            row.append(rmButton(() => { q.group_by.splice(i, 1); renderForm(); preview(); }));
            gb.append(row);
        });
        if ((q.group_by || []).length < 2) {
            gb.append(addButton('+ измерение', () => { (q.group_by ||= []).push(SCHEMA.dimensions[0]); renderForm(); preview(); }));
        }
        box.append(gb);

        // aggregations
        const ag = el('div', { className: 're-group' }, [el('b', { textContent: 'Агрегации (1..5)' })]);
        q.aggregations.forEach((a, i) => {
            const row = el('div', { className: 're-row' });
            row.append(select(Object.keys(SCHEMA.aggregations), a.fn, (v) => {
                a.fn = v;
                const need = SCHEMA.aggregations[v].field;
                if (need === 'none') delete a.field;
                else if (!a.field) a.field = need === 'metric' ? SCHEMA.metrics[0] : SCHEMA.dimensions[0];
                renderForm(); preview();
            }));
            const need = SCHEMA.aggregations[a.fn].field;
            if (need === 'metric') row.append(select(SCHEMA.metrics, a.field, (v) => { a.field = v; preview(); }));
            if (need === 'dimension') row.append(select(SCHEMA.dimensions, a.field, (v) => { a.field = v; preview(); }));
            row.append(textInput(a.alias, (v) => { a.alias = v; preview(); }, 'alias'));
            row.append(rmButton(() => { q.aggregations.splice(i, 1); renderForm(); preview(); }));
            ag.append(row);
            if (SCHEMA.aggregations[a.fn].filters) {
                ag.append(filtersBlock(a.filters ||= [], 'условия count_if'));
            }
        });
        if (q.aggregations.length < 5) {
            ag.append(addButton('+ агрегация', () => { q.aggregations.push({ fn: 'count', alias: 'a' + q.aggregations.length }); renderForm(); preview(); }));
        }
        box.append(ag);

        // filters
        box.append(filtersBlock(q.filters ||= [], 'Фильтры (AND)'));

        // sort / limit / sample / flags
        const aliases = q.aggregations.map((a) => a.alias);
        box.append(field('Сортировка', select(aliases, (q.sort && q.sort.by) || aliases[0],
            (v) => { q.sort = { by: v, dir: (q.sort && q.sort.dir) || 'desc' }; preview(); })));
        box.append(field('Направление', select(['desc', 'asc'], (q.sort && q.sort.dir) || 'desc',
            (v) => { q.sort = { by: (q.sort && q.sort.by) || aliases[0], dir: v }; preview(); })));
        box.append(field('Лимит групп', numInput(q.limit || 20, (v) => { q.limit = v; preview(); })));
        box.append(field('Сэмпл', select(['', '0.1', '0.01'], q.sample ? String(q.sample) : '',
            (v) => { q.sample = v ? +v : null; preview(); }, '100%')));
        box.append(field('top-N + «остальные»', checkbox(!!q.top_n_other, (v) => { q.top_n_other = v; preview(); })));
        box.append(field('Сравнить с пред. периодом', checkbox(!!q.compare_previous_period, (v) => { q.compare_previous_period = v; preview(); })));
    }

    function filtersBlock(filters, label) {
        const box = el('div', { className: 're-group' }, [el('b', { textContent: label })]);
        filters.forEach((f, i) => {
            const row = el('div', { className: 're-row' });
            const allFields = [...SCHEMA.dimensions, ...SCHEMA.metrics];
            row.append(select(allFields, f.field, (v) => {
                f.field = v;
                f.op = SCHEMA.dimensions.includes(v) ? '=' : '>';
                f.value = SCHEMA.dimensions.includes(v) ? '' : 0;
                renderForm(); preview();
            }));
            const ops = SCHEMA.dimensions.includes(f.field) ? SCHEMA.dimension_ops : SCHEMA.metric_ops;
            row.append(select(ops, f.op, (v) => { f.op = v; preview(); }));
            const multi = f.op === 'in' || f.op === 'not_in' || f.op === 'between';
            const val = multi ? (Array.isArray(f.value) ? f.value.join(',') : '') : f.value;
            row.append(textInput(val, (v) => {
                f.value = multi
                    ? v.split(',').map((s) => s.trim()).filter(Boolean).map((s) => (SCHEMA.dimensions.includes(f.field) ? s : +s))
                    : (SCHEMA.dimensions.includes(f.field) ? v : +v);
                preview();
            }, multi ? 'через запятую' : 'значение'));
            row.append(rmButton(() => { filters.splice(i, 1); renderForm(); preview(); }));
            box.append(row);
        });
        box.append(addButton('+ фильтр', () => { filters.push({ field: SCHEMA.dimensions[0], op: '=', value: '' }); renderForm(); preview(); }));
        return box;
    }

    /* --- виджеты-хелперы --- */
    function field(label, control) { return el('div', { className: 're-field' }, [el('label', { textContent: label }), control]); }
    function select(values, cur, onchange, emptyLabel) {
        const s = el('select');
        values.forEach((v) => s.append(option(v, v === '' ? (emptyLabel || '—') : v, cur)));
        s.value = cur; s.onchange = () => onchange(s.value); return s;
    }
    function textInput(val, oninput, ph) { const i = el('input', { value: val ?? '', placeholder: ph || '' }); i.oninput = () => oninput(i.value); return i; }
    function numInput(val, oninput) { const i = el('input', { type: 'number', value: val }); i.oninput = () => oninput(+i.value); return i; }
    function checkbox(val, onchange) { const i = el('input', { type: 'checkbox', checked: val }); i.onchange = () => onchange(i.checked); return i; }
    function rmButton(onclick) { const b = el('button', { className: 'rm', textContent: '✕' }); b.onclick = onclick; return b; }
    function addButton(text, onclick) { const b = el('button', { textContent: text }); b.onclick = onclick; return b; }

    /* --- предпросмотр (debounce) --- */
    let previewTimer = null;
    function preview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(() => {
            if (selected < 0) return;
            renderReport({ widgets: [config.widgets[selected]] }, $('re-preview-body'));
        }, 500);
    }

    /* --- вкладки --- */
    document.querySelectorAll('.re-tabs button').forEach((btn) => {
        btn.onclick = () => {
            document.querySelectorAll('.re-tabs button').forEach((b) => b.classList.toggle('active', b === btn));
            $('re-tab-form').hidden = btn.dataset.tab !== 'form';
            $('re-tab-json').hidden = btn.dataset.tab !== 'json';
            if (btn.dataset.tab === 'json') $('re-json').value = JSON.stringify(config, null, 2);
        };
    });
    $('re-apply-json').onclick = () => {
        try {
            config = JSON.parse($('re-json').value);
            selected = config.widgets.length ? 0 : -1;
            $('re-name').value = config.name || '';
            $('re-desc').value = config.description || '';
            showErrors([]);
            renderAll();
        } catch (e) { showErrors(['JSON: ' + e.message]); }
    };

    /* --- сохранение --- */
    $('re-save').onclick = async () => {
        const url = reportId ? `/api/reports/${reportId}` : '/api/reports';
        const resp = await fetch(url, {
            method: reportId ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(config),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok) { showErrors(data.errors || ['HTTP ' + resp.status]); return; }
        showErrors([]);
        if (!reportId && data.id) { location.href = `/report/${data.id}/edit`; return; }
        $('re-status').textContent = 'Сохранено ✓';
        setTimeout(() => { $('re-status').textContent = ''; }, 2000);
    };

    function showErrors(errors) {
        const ul = $('re-errors');
        ul.innerHTML = '';
        errors.forEach((e) => ul.append(el('li', { textContent: e })));
    }

    function renderAll() { renderWidgetList(); renderForm(); preview(); }
    renderAll();

    // экспорт для чат-панели ИИ (PLAN_AI фаза I3)
    window.reportEditorApply = (cfg) => {
        config = cfg;
        selected = config.widgets.length ? 0 : -1;
        $('re-name').value = config.name || '';
        $('re-desc').value = config.description || '';
        renderAll();
    };
    window.reportEditorGetConfig = () => config;
})();
