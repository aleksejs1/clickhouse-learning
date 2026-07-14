/* Таймлайн на главной и сетка сравнительных графиков на странице события
   (см. docs/API.md §4). */

const COLOR_SIMILAR = '#f59e0b';
const COLOR_OTHER = '#5b8cc4';
const COLOR_MUTED = '#cbd5e1';

/* --- Таймлайн: события по часам, выделение диапазона мышью задаёт период --- */

function initTimeline(canvas) {
    const points = JSON.parse(canvas.dataset.points);
    const labels = points.map((p) => p.hour);
    const counts = points.map((p) => Number(p.c));
    const from = canvas.dataset.from.replace('T', ' ');
    const to = canvas.dataset.to.replace('T', ' ');
    const inPeriod = (h) => (!from || h >= from) && (!to || h <= to);

    let drag = null; // {x1, x2} в пикселях канваса, пока тянут мышью

    const chart = new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: counts,
                backgroundColor: labels.map((h) => (inPeriod(h) ? COLOR_OTHER : COLOR_MUTED)),
            }],
        },
        options: {
            animation: false,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { maxTicksLimit: 15, maxRotation: 0, font: { size: 10 } } },
                y: { grid: { color: '#eef2f7' }, ticks: { maxTicksLimit: 4 } },
            },
        },
        plugins: [{
            id: 'brush',
            afterDraw(c) {
                if (!drag) return;
                const { ctx, chartArea } = c;
                ctx.save();
                ctx.fillStyle = 'rgba(245, 158, 11, 0.25)';
                ctx.fillRect(Math.min(drag.x1, drag.x2), chartArea.top,
                    Math.abs(drag.x2 - drag.x1), chartArea.bottom - chartArea.top);
                ctx.restore();
            },
        }],
    });

    canvas.addEventListener('mousedown', (e) => { drag = { x1: e.offsetX, x2: e.offsetX }; });
    canvas.addEventListener('mousemove', (e) => {
        if (drag) {
            drag.x2 = e.offsetX;
            chart.draw();
        }
    });
    window.addEventListener('mouseup', () => {
        if (!drag) return;
        const [x1, x2] = [Math.min(drag.x1, drag.x2), Math.max(drag.x1, drag.x2)];
        drag = null;
        if (x2 - x1 < 5) { // случайный клик, не выделение
            chart.draw();
            return;
        }
        const clamp = (i) => Math.min(labels.length - 1, Math.max(0, Math.round(i)));
        const i1 = clamp(chart.scales.x.getValueForPixel(x1));
        const i2 = clamp(chart.scales.x.getValueForPixel(x2));
        const params = new URLSearchParams(location.search);
        params.set('from', labels[i1].replace(' ', 'T'));
        params.set('to', labels[i2].slice(0, 14).replace(' ', 'T') + '59:59'); // конец последнего часа
        params.delete('page');
        location.search = params;
    });
}

const timelineCanvas = document.getElementById('timeline');
if (timelineCanvas) {
    initTimeline(timelineCanvas);
}

/* --- Сетка сравнительных графиков --- */

async function drawChart(params, field) {
    const resp = await fetch(`/api/chart/${field}?${params}`);
    if (!resp.ok) {
        throw new Error(`${field}: HTTP ${resp.status}`);
    }
    const d = await resp.json();

    const canvas = document.getElementById(`chart-${field}`);
    canvas.closest('figure').querySelector('figcaption').textContent
        = `${field} · Δ ${d.divergence}%`;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: d.labels,
            datasets: [
                {
                    label: `похожие (N=${d.similar_total})`,
                    data: d.similar_pct,
                    backgroundColor: COLOR_SIMILAR,
                    borderRadius: 4,
                },
                {
                    label: `остальные (N=${d.other_total})`,
                    data: d.other_pct,
                    backgroundColor: COLOR_OTHER,
                    borderRadius: 4,
                },
            ],
        },
        options: {
            animation: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12 } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y}%`,
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, ticks: { autoSkip: true, maxRotation: 60, font: { size: 10 } } },
                y: { grid: { color: '#eef2f7' }, ticks: { callback: (v) => v + '%' } },
            },
        },
    });

    return d.divergence;
}

const root = document.getElementById('charts');
if (root) {
    const { similarBy, similarValue, from, to, fields } = root.dataset;
    const params = new URLSearchParams({ similar_by: similarBy, value: similarValue });
    if (from) params.set('from', from);
    if (to) params.set('to', to);

    // Когда все графики загружены — отсортировать по расхождению (самые
    // аномальные поля первыми) и подсветить явных лидеров
    const jobs = fields.split(',').map(async (field) => ({
        field,
        divergence: await drawChart(params, field),
    }));
    Promise.allSettled(jobs).then((results) => {
        results
            .filter((r) => r.status === 'fulfilled')
            .map((r) => r.value)
            .sort((a, b) => b.divergence - a.divergence)
            .forEach(({ field, divergence }, rank) => {
                const figure = document.getElementById(`chart-${field}`).closest('figure');
                figure.style.order = rank;
                if (rank < 3 && divergence >= 10) {
                    figure.classList.add('anomaly');
                }
            });
        results
            .filter((r) => r.status === 'rejected')
            .forEach((r) => console.error(r.reason));
    });
}
