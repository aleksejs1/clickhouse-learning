/* Редактор алертов на Drawflow (docs/ALERTS.md §7).
   Хранится семантический JSON (nodes/edges), а не экспорт Drawflow;
   конвертеры toDrawflow / fromDrawflow — ниже. */

(function () {
    const root = document.getElementById('alert-editor');
    if (!root) return;

    const alertId = root.dataset.id || null;
    const CATALOG = {};
    JSON.parse(root.dataset.catalog).forEach((n) => { CATALOG[n.type] = n; });
    const FIELDS = JSON.parse(root.dataset.fields);
    let meta = JSON.parse(root.dataset.config); // держим name/description/enabled

    const $ = (id) => document.getElementById(id);
    const el = (tag, props = {}, kids = []) => {
        const n = Object.assign(document.createElement(tag), props);
        for (const k of kids) n.append(k);
        return n;
    };

    /* --- Drawflow --- */
    const editor = new Drawflow($('ae-canvas'));
    editor.reroute = true;
    editor.start();

    let semCounter = 0;
    const nextSemId = () => {
        let id;
        do { id = 'n' + (++semCounter); } while (usedSemIds().has(id));
        return id;
    };
    function usedSemIds() {
        const data = editor.export().drawflow.Home.data;
        return new Set(Object.values(data).map((n) => n.data.semanticId));
    }

    function nodeHtml(spec, semId) {
        return `<div class="anode" data-sem="${semId}"><b>${spec.label}</b><small>${spec.type}</small></div>`;
    }

    function addNode(type, x, y, semId, params) {
        const spec = CATALOG[type];
        semId = semId || nextSemId();
        params = params || defaults(spec);
        return editor.addNode(type, spec.inputs, spec.outputs, x, y, spec.category,
            { type, semanticId: semId, params }, nodeHtml(spec, semId));
    }

    function defaults(spec) {
        const p = {};
        for (const param of spec.params) {
            if (param.default !== undefined) p[param.name] = param.default;
        }
        return p;
    }

    /* --- конвертеры semantic <-> drawflow --- */
    function toDrawflow(config) {
        editor.clear();
        semCounter = 0;
        const idMap = {};
        for (const node of config.nodes || []) {
            const pos = node.position || { x: 60, y: 80 };
            idMap[node.id] = addNode(node.type, pos.x, pos.y, node.id, node.params || {});
            const m = /^n(\d+)$/.exec(node.id);
            if (m) semCounter = Math.max(semCounter, +m[1]);
        }
        for (const edge of config.edges || []) {
            if (!(edge.from in idMap) || !(edge.to in idMap)) continue;
            const outIdx = edge.from_port === 'false' ? 2 : 1; // condition: true=output_1, false=output_2
            try { editor.addConnection(idMap[edge.from], idMap[edge.to], 'output_' + outIdx, 'input_1'); } catch (e) { /* ignore */ }
        }
    }

    function fromDrawflow() {
        const data = editor.export().drawflow.Home.data;
        const dfToSem = {};
        for (const dfId in data) dfToSem[dfId] = data[dfId].data.semanticId;

        const nodes = [];
        const edges = [];
        for (const dfId in data) {
            const n = data[dfId];
            nodes.push({
                id: n.data.semanticId,
                type: n.data.type,
                position: { x: Math.round(n.pos_x), y: Math.round(n.pos_y) },
                params: n.data.params || {},
            });
            for (const outName in n.outputs) {
                const portIdx = +outName.split('_')[1];
                for (const conn of n.outputs[outName].connections) {
                    const edge = { from: n.data.semanticId, to: dfToSem[conn.node] };
                    if ((CATALOG[n.data.type].outputs || 0) >= 2) {
                        edge.from_port = portIdx === 2 ? 'false' : 'true';
                    }
                    edges.push(edge);
                }
            }
        }
        return { ...meta, name: $('ae-name').value, description: $('ae-desc').value, nodes, edges };
    }

    /* --- палитра --- */
    function renderPalette() {
        const body = $('ae-palette-body');
        body.innerHTML = '';
        const cats = { trigger: 'Триггеры', condition: 'Условия', action: 'Действия' };
        for (const cat in cats) {
            body.append(el('div', { className: 'ae-cat', textContent: cats[cat] }));
            Object.values(CATALOG).filter((n) => n.category === cat).forEach((spec) => {
                const item = el('div', { className: 'ae-pal-node ' + cat, textContent: spec.label, draggable: true });
                item.title = spec.type;
                item.ondragstart = (e) => e.dataTransfer.setData('node-type', spec.type);
                item.ondblclick = () => { addNode(spec.type, 40 + Math.random() * 60, 40 + Math.random() * 60); };
                body.append(item);
            });
        }
    }

    const canvas = $('ae-canvas');
    canvas.addEventListener('dragover', (e) => e.preventDefault());
    canvas.addEventListener('drop', (e) => {
        e.preventDefault();
        const type = e.dataTransfer.getData('node-type');
        if (!type) return;
        const rect = editor.precanvas.getBoundingClientRect();
        addNode(type, (e.clientX - rect.x) / editor.zoom, (e.clientY - rect.y) / editor.zoom);
    });

    /* --- панель свойств --- */
    let selectedDfId = null;
    editor.on('nodeSelected', (dfId) => { selectedDfId = dfId; renderProps(); });
    editor.on('nodeUnselected', () => { selectedDfId = null; renderProps(); });
    editor.on('nodeRemoved', () => { selectedDfId = null; renderProps(); });

    function renderProps() {
        const box = $('ae-props-body');
        box.innerHTML = '';
        box.classList.toggle('muted', selectedDfId === null);
        if (selectedDfId === null) { box.textContent = 'Выберите узел на канвасе'; return; }

        const node = editor.getNodeFromId(selectedDfId);
        const spec = CATALOG[node.data.type];
        const params = node.data.params;

        box.append(el('div', { className: 're-sub', textContent: spec.label }));
        if (spec.outputs >= 2) {
            box.append(el('p', { className: 'muted', style: 'font-size:12px',
                textContent: 'Верхний выход = «да», нижний = «нет»' }));
        }

        const commit = () => editor.updateNodeDataFromId(selectedDfId, { ...node.data, params });

        for (const p of spec.params) {
            box.append(paramField(p, params, commit));
        }
    }

    function paramField(p, params, commit) {
        const wrap = el('div', { className: 're-field' });
        wrap.append(el('label', { textContent: p.name }));
        const cur = params[p.name];

        const set = (v) => { params[p.name] = v; commit(); };

        switch (p.type) {
            case 'enum':
            case 'dimension_field':
            case 'metric_field': {
                const s = el('select');
                (p.values || []).forEach((v) => s.append(new Option(v, v)));
                s.value = cur ?? p.values[0];
                s.onchange = () => set(s.value);
                wrap.append(s);
                break;
            }
            case 'enum_multi': {
                wrap.classList.remove('re-field');
                wrap.append(el('label', { textContent: p.name, style: 'display:block' }));
                const box = el('div');
                (p.values || []).forEach((v) => {
                    const cb = el('input', { type: 'checkbox', checked: (cur || []).includes(v) });
                    cb.onchange = () => {
                        const arr = new Set(params[p.name] || []);
                        cb.checked ? arr.add(v) : arr.delete(v);
                        set([...arr]);
                    };
                    box.append(el('label', { style: 'margin-right:8px; font-size:12px' }, [cb, document.createTextNode(' ' + v)]));
                });
                wrap.append(box);
                break;
            }
            case 'number': {
                const i = el('input', { type: 'number', value: cur ?? '' });
                i.oninput = () => set(i.value === '' ? null : +i.value);
                wrap.append(i);
                break;
            }
            case 'template': {
                const t = el('textarea', { value: cur ?? '', rows: 2, style: 'width:150px', placeholder: '{{vehicle_id}}, {{value}}…' });
                t.oninput = () => set(t.value);
                wrap.append(t);
                break;
            }
            case 'filters': {
                wrap.classList.remove('re-field');
                wrap.append(filtersEditor(params, p.name, commit));
                break;
            }
            default: { // string, cron
                const i = el('input', { value: cur ?? '', placeholder: 'cron' === p.type ? '0 8 * * *' : '' });
                i.oninput = () => set(i.value);
                wrap.append(i);
            }
        }
        return wrap;
    }

    function filtersEditor(params, name, commit) {
        const list = params[name] ||= [];
        const box = el('div', { className: 're-group' }, [el('b', { textContent: name })]);
        const allFields = [...FIELDS.dimensions, ...FIELDS.metrics];
        const rerender = () => { const fresh = filtersEditor(params, name, commit); box.replaceWith(fresh); };

        list.forEach((f, i) => {
            const row = el('div', { className: 're-row' });
            const fs = el('select');
            allFields.forEach((v) => fs.append(new Option(v, v)));
            fs.value = f.field;
            fs.onchange = () => { f.field = fs.value; f.op = FIELDS.dimensions.includes(fs.value) ? 'in' : '>'; f.value = FIELDS.dimensions.includes(fs.value) ? [] : 0; commit(); rerender(); };
            row.append(fs);

            const isDim = FIELDS.dimensions.includes(f.field);
            const ops = isDim ? ['=', '!=', 'in', 'not_in'] : ['>', '>=', '<', '<=', '=', '!=', 'between'];
            const os = el('select');
            ops.forEach((v) => os.append(new Option(v, v)));
            os.value = f.op;
            os.onchange = () => { f.op = os.value; commit(); };
            row.append(os);

            const multi = f.op === 'in' || f.op === 'not_in' || f.op === 'between';
            const iv = el('input', { value: Array.isArray(f.value) ? f.value.join(',') : f.value, placeholder: multi ? 'через запятую' : '' });
            iv.oninput = () => {
                f.value = multi ? iv.value.split(',').map((s) => s.trim()).filter(Boolean).map((s) => (isDim ? s : +s))
                    : (isDim ? iv.value : +iv.value);
                commit();
            };
            row.append(iv);

            const rm = el('button', { className: 'rm', textContent: '✕' });
            rm.onclick = () => { list.splice(i, 1); commit(); rerender(); };
            row.append(rm);
            box.append(row);
        });
        const add = el('button', { textContent: '+ фильтр' });
        add.onclick = () => { list.push({ field: FIELDS.dimensions[0], op: 'in', value: [] }); commit(); rerender(); };
        box.append(add);
        return box;
    }

    /* --- проверка / сохранение / JSON --- */
    async function validate(config) {
        const resp = await fetch('/api/alerts/validate', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(config),
        });
        return resp.json();
    }

    function showErrors(errors) {
        const ul = $('ae-errors');
        ul.innerHTML = '';
        (errors || []).forEach((e) => ul.append(el('li', { textContent: (e.node ? e.node + ': ' : '') + e.message })));
    }

    $('ae-validate').onclick = async () => {
        const res = await validate(fromDrawflow());
        showErrors(res.errors);
        $('ae-summary').textContent = res.summary || '';
        $('ae-status').textContent = res.valid ? 'Граф валиден ✓' : '';
    };

    $('ae-save').onclick = async () => {
        const config = fromDrawflow();
        const res = await validate(config);
        if (!res.valid) { showErrors(res.errors); $('ae-summary').textContent = res.summary || ''; return; }
        showErrors([]);
        const url = alertId ? `/api/alerts/${alertId}` : '/api/alerts';
        const resp = await fetch(url, {
            method: alertId ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(config),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok) { showErrors(data.errors || [{ message: 'HTTP ' + resp.status }]); return; }
        if (!alertId && data.id) { location.href = `/alert/${data.id}/edit`; return; }
        $('ae-status').textContent = 'Сохранено ✓';
        setTimeout(() => { $('ae-status').textContent = ''; }, 2000);
    };

    document.querySelectorAll('.ae-tabs button').forEach((btn) => {
        btn.onclick = () => {
            document.querySelectorAll('.ae-tabs button').forEach((b) => b.classList.toggle('active', b === btn));
            $('ae-tab-json').hidden = btn.dataset.tab !== 'json';
            if (btn.dataset.tab === 'json') $('ae-json').value = JSON.stringify(fromDrawflow(), null, 2);
        };
    });
    $('ae-apply-json').onclick = () => {
        try {
            const cfg = JSON.parse($('ae-json').value);
            applyConfig(cfg);
            showErrors([]);
        } catch (e) { showErrors([{ message: 'JSON: ' + e.message }]); }
    };

    /* --- инициализация --- */
    function applyConfig(config) {
        meta = { version: config.version || 1, name: config.name || '', description: config.description || '', enabled: config.enabled !== false };
        $('ae-name').value = meta.name;
        $('ae-desc').value = meta.description;
        toDrawflow(config);
        renderProps();
    }

    $('ae-name').value = meta.name || '';
    $('ae-desc').value = meta.description || '';
    renderPalette();
    applyConfig(meta);

    // точки входа для чат-панели ИИ (PLAN_AI фаза I3)
    window.alertEditorApply = applyConfig;
    window.alertEditorGetConfig = () => fromDrawflow();
})();
