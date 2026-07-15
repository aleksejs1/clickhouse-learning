/* Репорты: просмотр (сетка виджетов) и рендер отдельного виджета.
   Форматы данных — docs/REPORTS.md §6 (/api/report-data). */

const R_PALETTE = ['#5b8cc4', '#f59e0b', '#4daf7c', '#b56cc8', '#c95f5f', '#8a8f3c'];

const rFmt = (n) => (Math.abs(n) >= 1e6 ? (n / 1e6).toFixed(1) + 'M'
    : Math.abs(n) >= 1e3 ? (n / 1e3).toFixed(1) + 'k' : String(n));

function rEsc(s) {
    const div = document.createElement('div');
    div.textContent = String(s);
    return div.innerHTML;
}

async function fetchWidgetData(widget) {
    const resp = await fetch('/api/report-data', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ query: widget.query, viz_type: widget.viz.type }),
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) {
        throw new Error((data.errors || ['HTTP ' + resp.status]).join('; '));
    }
    return data;
}

/* Рендер всего репорта в grid-контейнер. Возвращает Promise всех виджетов. */
function renderReport(config, root) {
    root.innerHTML = '';
    const jobs = [];
    for (const w of config.widgets || []) {
        const fig = document.createElement('figure');
        fig.className = 'w' + (w.width || 1);
        fig.innerHTML = `<figcaption>${rEsc(w.title)}</figcaption>`
            + '<div class="rbody muted">загрузка…</div><div class="rstats muted"></div>';
        root.appendChild(fig);
        jobs.push(fetchWidgetData(w)
            .then((d) => renderWidget(fig, d))
            .catch((err) => { fig.querySelector('.rbody').textContent = 'Ошибка: ' + err.message; }));
    }
    return Promise.allSettled(jobs);
}

function renderWidget(fig, d) {
    const body = fig.querySelector('.rbody');
    body.classList.remove('muted');
    body.innerHTML = '';

    switch (d.viz_type) {
        case 'stat': renderStat(body, d); break;
        case 'table': renderTable(body, d); break;
        case 'heatmap': renderHeatmap(body, d); break;
        default: renderChart(body, d);
    }

    const qs = (d.meta && d.meta.queries) || [];
    if (qs.length) {
        const read = qs.reduce((s, q) => s + q.read_rows, 0);
        const ms = qs.reduce((s, q) => s + q.elapsed_ms, 0);
        fig.querySelector('.rstats').textContent =
            `прочитано ${rFmt(read)} строк · ${ms.toFixed(0)} мс · ${qs.length} SQL`;
    }
}

function renderStat(body, d) {
    const value = (d.series[0] && d.series[0].data[0]) ?? 0;
    let html = `<div class="stat-value">${rEsc(rFmt(value))}</div>`;
    if (d.meta && d.meta.delta_pct !== undefined && d.meta.delta_pct !== null) {
        const up = d.meta.delta_pct >= 0;
        html += `<div class="stat-delta ${up ? 'up' : 'down'}">${up ? '▲' : '▼'} ${Math.abs(d.meta.delta_pct)}%`
            + ` <span class="muted" style="font-weight:400">к пред. периоду (${rEsc(rFmt(d.meta.previous))})</span></div>`;
    }
    body.innerHTML = html;
}

function renderTable(body, d) {
    const head = d.columns.map((c) => `<th>${rEsc(c.label)}</th>`).join('');
    const rows = d.rows.slice(0, 100).map((row) =>
        '<tr>' + d.columns.map((c) => `<td>${rEsc(row[c.key] ?? '')}</td>`).join('') + '</tr>').join('');
    body.innerHTML = `<table class="rtable"><thead><tr>${head}</tr></thead><tbody>${rows}</tbody></table>`;
}

function renderHeatmap(body, d) {
    // строки — группы (series), колонки — время (labels); интенсивность от максимума
    let max = 0;
    for (const s of d.series) for (const v of s.data) max = Math.max(max, v || 0);
    const shortLabel = (l) => l.slice(5, 10); // MM-DD из YYYY-MM-DD…
    const head = '<th></th>' + d.labels.map((l) => `<th>${rEsc(shortLabel(l))}</th>`).join('');
    const rows = d.series.map((s) => {
        const cells = s.data.map((v) => {
            const a = max ? (v || 0) / max : 0;
            const color = a > 0.55 ? '#fff' : '#1e293b';
            return `<td style="background: rgba(217, 119, 6, ${(a * 0.9).toFixed(2)}); color: ${color}">${rEsc(rFmt(v || 0))}</td>`;
        }).join('');
        return `<tr><th style="text-align:left">${rEsc(s.name)}</th>${cells}</tr>`;
    }).join('');
    body.innerHTML = `<div style="overflow-x:auto"><table class="rtable hm"><thead><tr>${head}</tr></thead><tbody>${rows}</tbody></table></div>`;
}

function renderChart(body, d) {
    const canvas = document.createElement('canvas');
    body.appendChild(canvas);
    const isLine = d.viz_type === 'line';
    const stacked = d.viz_type === 'stacked_bar';
    const shortLabels = d.labels.map((l) => String(l).replace(':00:00', 'ч').slice(0, 16));

    new Chart(canvas, {
        type: isLine ? 'line' : 'bar',
        data: {
            labels: shortLabels,
            datasets: d.series.map((s, i) => ({
                label: s.name,
                data: s.data,
                backgroundColor: R_PALETTE[i % R_PALETTE.length],
                borderColor: R_PALETTE[i % R_PALETTE.length],
                borderRadius: isLine ? 0 : 3,
                pointRadius: 0,
                borderWidth: isLine ? 2 : 0,
                tension: 0,
            })),
        },
        options: {
            animation: false,
            plugins: { legend: { display: d.series.length > 1, position: 'bottom', labels: { boxWidth: 12 } } },
            scales: {
                x: { stacked, grid: { display: false }, ticks: { autoSkip: true, maxRotation: 60, font: { size: 10 } } },
                y: { stacked, grid: { color: '#eef2f7' } },
            },
        },
    });
}

/* --- Страница просмотра --- */
const reportViewRoot = document.getElementById('report-view');
if (reportViewRoot) {
    renderReport(JSON.parse(reportViewRoot.dataset.config), reportViewRoot);
}
