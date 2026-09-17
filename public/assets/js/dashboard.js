/* ============================================================
   iMplement ERP — Dashboard charts (dashboard.js)
   ------------------------------------------------------------
   Renders the monthly-sales and top-products charts using
   Chart.js. Chart data is passed from PHP via the #app-data
   JSON block (no inline logic). Degrades gracefully when
   Chart.js or data is unavailable.
   ============================================================ */
(function () {
    'use strict';

    function readData() {
        const el = document.getElementById('app-data');
        if (!el) return {};
        try { return JSON.parse(el.textContent) || {}; } catch (e) { return {}; }
    }

    function showEmpty(canvas) {
        const wrap = canvas.closest('.chart-wrap');
        canvas.hidden = true;
        wrap?.querySelector('[data-chart-empty]')?.removeAttribute('hidden');
    }

    // Brand palette pulled from CSS variables so charts stay on-brand.
    function cssVar(name, fallback) {
        const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    }

    function render() {
        const data = readData();
        const hasChart = typeof window.Chart !== 'undefined';

        const primary = cssVar('--primary', '#29c5f0');
        const violet = cssVar('--violet', '#7c3aed');
        const ink = cssVar('--ink-soft', '#7a7f94');
        const grid = 'rgba(122,127,148,0.12)';

        if (window.Chart) {
            Chart.defaults.color = ink;
            Chart.defaults.font.family = 'Poppins, system-ui, sans-serif';
        }

        // ── Monthly sales vs expenses (line) ──────────────
        const salesCanvas = document.getElementById('chartMonthlySales');
        const sales = data.salesChart || { labels: [], values: [] };
        const expenses = data.expensesChart || { labels: [], values: [] };
        if (salesCanvas) {
            const hasData = (sales.values && sales.values.some((v) => v > 0))
                || (expenses.values && expenses.values.some((v) => v > 0));
            if (!hasChart || !hasData) {
                showEmpty(salesCanvas);
            } else {
                const ctx = salesCanvas.getContext('2d');
                const grad = ctx.createLinearGradient(0, 0, 0, 220);
                grad.addColorStop(0, 'rgba(41,197,240,0.35)');
                grad.addColorStop(1, 'rgba(41,197,240,0)');
                const gold = cssVar('--gold', '#f0b429');
                const datasets = [{
                    label: 'Sales',
                    data: sales.values,
                    borderColor: primary,
                    backgroundColor: grad,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: primary,
                }];
                if (expenses.values && expenses.values.some((v) => v > 0)) {
                    datasets.push({
                        label: 'Expenses',
                        data: expenses.values,
                        borderColor: gold,
                        borderWidth: 2,
                        borderDash: [5, 4],
                        fill: false,
                        tension: 0.35,
                        pointRadius: 2,
                        pointBackgroundColor: gold,
                    });
                }
                new Chart(ctx, {
                    type: 'line',
                    data: { labels: sales.labels, datasets: datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: datasets.length > 1, labels: { boxWidth: 18 } } },
                        scales: {
                            x: { grid: { color: grid }, border: { display: false } },
                            y: { grid: { color: grid }, border: { display: false }, beginAtZero: true },
                        },
                    },
                });
            }
        }

        // ── Top products (bar) ────────────────────────────
        const topCanvas = document.getElementById('chartTopProducts');
        const top = data.topChart || { labels: [], values: [] };
        if (topCanvas) {
            const hasData = top.values && top.values.length > 0;
            if (!hasChart || !hasData) {
                showEmpty(topCanvas);
            } else {
                new Chart(topCanvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: top.labels,
                        datasets: [{
                            label: 'Qty sold',
                            data: top.values,
                            backgroundColor: violet,
                            borderRadius: 6,
                            maxBarThickness: 42,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { grid: { display: false }, border: { display: false } },
                            y: { grid: { color: grid }, border: { display: false }, beginAtZero: true },
                        },
                    },
                });
            }
        }
    }

    // Chart.js is loaded with `defer`; wait for full load so the
    // global is available regardless of script order.
    if (document.readyState === 'complete') {
        render();
    } else {
        window.addEventListener('load', render);
    }
})();
