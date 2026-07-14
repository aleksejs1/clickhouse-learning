/* Сетка сравнительных графиков на странице события (см. docs/API.md §4). */

const COLOR_SIMILAR = '#f59e0b';
const COLOR_OTHER = '#5b8cc4';

async function drawChart(params, field) {
    const resp = await fetch(`/api/chart/${field}?${params}`);
    if (!resp.ok) {
        throw new Error(`${field}: HTTP ${resp.status}`);
    }
    const d = await resp.json();

    new Chart(document.getElementById(`chart-${field}`), {
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
}

const root = document.getElementById('charts');
if (root) {
    const { similarBy, similarValue, from, to, fields } = root.dataset;
    const params = new URLSearchParams({ similar_by: similarBy, value: similarValue });
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    for (const field of fields.split(',')) {
        drawChart(params, field).catch(console.error);
    }
}
