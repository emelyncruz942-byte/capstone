const CHART_PALETTES = {
    dark: {
        cyan: '#22d3ee', purple: '#a78bfa', pink: '#f472b6',
        green: '#4ade80', orange: '#fbbf24', red: '#fb7185',
        grid: '#2c3c54', text: '#c4d2e7', heading: '#f1f5f9',
        surface: '#111c2e', body: '#c4d2e7',
    }
};
// Analytics keeps one proven high-contrast palette in both application modes.
// Changing an existing Chart.js instance during a dashboard theme transition
// can leave it unmeasurable, so charts intentionally retain the dark palette
// even on the light site surface.
const CHART_DEFAULTS = { color: CHART_PALETTES.dark };
const themedCharts = new Map();
let chartRenderVersion = 0;
const REPORT_CHART_IDS = [
    'chart-attempts',
    'chart-distribution',
    'chart-class-accuracy',
    'chart-registrations',
    'chart-roles',
];

function rememberChart(chart) {
    themedCharts.set(chart, true);
    return chart;
}

function applyChartDefaults() {
    CHART_DEFAULTS.color = CHART_PALETTES.dark;
    if (typeof Chart === 'undefined') return;
    Chart.defaults.color       = CHART_DEFAULTS.color.text;
    Chart.defaults.borderColor = CHART_DEFAULTS.color.grid;
    Chart.defaults.font.family = 'Rajdhani, sans-serif';
    Chart.defaults.font.size   = 14;
    const reduceMotion = typeof globalThis.matchMedia === 'function'
        && globalThis.matchMedia('(prefers-reduced-motion: reduce)').matches;
    Chart.defaults.animation = reduceMotion ? false : { duration: 320 };
}

function waitForChartLibrary() {
    if (typeof Chart !== 'undefined') return Promise.resolve(true);

    return new Promise(resolve => {
        const onReady = () => {
            globalThis.clearTimeout(timeout);
            resolve(typeof Chart !== 'undefined');
        };
        const timeout = globalThis.setTimeout(() => {
            document.removeEventListener('mathverse:charts-ready', onReady);
            resolve(false);
        }, 10000);
        document.addEventListener('mathverse:charts-ready', onReady, { once: true });
    });
}

function chartTooltip(color = CHART_DEFAULTS.color.grid) {
    return {
        enabled: true,
        backgroundColor: CHART_DEFAULTS.color.surface,
        borderColor: color,
        borderWidth: 1,
        titleColor: CHART_DEFAULTS.color.heading,
        bodyColor: CHART_DEFAULTS.color.body,
        titleFont: { size: 14, weight: '600' },
        bodyFont: { size: 14 },
        cornerRadius: 10,
        padding: 12,
        displayColors: false,
    };
}

function isLiveChart(chart) {
    return Boolean(chart?.canvas && chart.canvas.isConnected !== false && chart.ctx !== null);
}

function pruneCharts() {
    for (const [chart] of themedCharts) {
        if (!isLiveChart(chart)) themedCharts.delete(chart);
    }
}

function isStatsSectionActive(role) {
    const dashboard = document.getElementById('dashboard-content');
    const section = document.getElementById('sec-stats');
    return dashboard?.dataset?.dashboardRole === role
        && section != null
        && !section.classList.contains('hidden');
}

function isStatsPanelVisible(role) {
    const content = document.getElementById('stats-content');
    return isStatsSectionActive(role)
        && content != null
        && !content.classList.contains('hidden');
}

function isDashboardRole(role) {
    return document.getElementById('dashboard-content')?.dataset?.dashboardRole === role;
}

function scheduleStatsRender(role, chartIds, render) {
    const version = ++chartRenderVersion;
    const draw = () => {
        if (version !== chartRenderVersion || !isStatsPanelVisible(role)) return;
        destroyCharts(chartIds);
        render();
    };

    // Two paints let the now-visible section obtain its real dimensions before
    // Chart.js measures it. Do not resize already-created hidden canvases:
    // that is what caused intermittent blank charts after section/theme work.
    if (typeof globalThis.requestAnimationFrame === 'function') {
        globalThis.requestAnimationFrame(() => globalThis.requestAnimationFrame(draw));
        return;
    }
    globalThis.setTimeout(draw, 0);
}

function describeChart(canvas, title, labels, data) {
    if (typeof canvas.setAttribute !== 'function') return;
    // Canvas pixels are otherwise silent to assistive technology. Keep the
    // overview concise even when the complete reporting history is plotted.
    const values = labels.length <= 12
        ? labels.map((label, index) => `${label}: ${data[index] ?? 0}`).join('; ')
        : `${labels.length} reporting periods, from ${labels[0]} to ${labels[labels.length - 1]}. Latest value: ${data[data.length - 1] ?? 0}`;
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', `${title}. ${values}`);
}

// ── Chart builders ────────────────────────────────────────

function buildLineChart(canvasId, labels, data, label, color) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    describeChart(ctx, label, labels, data);
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label,
                data,
                borderColor:          color,
                backgroundColor:      color + '20',
                borderWidth:          2,
                pointBackgroundColor: color,
                pointRadius:          labels.length > 45 ? 0 : 3,
                pointHoverRadius:     5,
                pointHitRadius:       16,
                fill:                 true,
                tension:              0.4,
            }]
        },
        options: {
            responsive:          true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: chartTooltip(color)
            },
            scales: {
                x: {
                    grid:  { color: CHART_DEFAULTS.color.grid },
                    ticks: { color: CHART_DEFAULTS.color.text, maxRotation: 30, autoSkip: true, maxTicksLimit: 8 }
                },
                y: {
                    beginAtZero: true,
                    grid:        { color: CHART_DEFAULTS.color.grid },
                    ticks:       { color: CHART_DEFAULTS.color.text, stepSize: 1 }
                }
            }
        }
    });
    return rememberChart(chart);
}

function buildBarChart(canvasId, labels, data, label, color) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    describeChart(ctx, label, labels, data);
    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label,
                data,
                backgroundColor: color + '80',
                borderColor:     color,
                borderWidth:     1,
                borderRadius:    4,
            }]
        },
        options: {
            responsive:          true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...chartTooltip(color),
                    callbacks: {
                        label: ctx => ` ${ctx.parsed.y}%`
                    }
                }
            },
            scales: {
                x: {
                    grid:  { color: CHART_DEFAULTS.color.grid },
                    ticks: {
                        color:       CHART_DEFAULTS.color.text,
                        maxRotation: 45,
                        font:        { size: 14 },
                        autoSkip:    true,
                    }
                },
                y: {
                    beginAtZero: true,
                    max:         100,
                    grid:        { color: CHART_DEFAULTS.color.grid },
                    ticks: {
                        color:    CHART_DEFAULTS.color.text,
                        stepSize: 25,
                        callback: v => v + '%'
                    }
                }
            }
        }
    });
    return rememberChart(chart);
}

function buildDoughnutChart(canvasId, labels, data, colors) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    describeChart(ctx, 'Distribution', labels, data);
    const chart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: colors.map(color => color + '99'),
                borderColor:     colors,
                borderWidth:     2,
                hoverOffset:     8,
            }]
        },
        options: {
            responsive:          true,
            maintainAspectRatio: true,
            cutout:              '65%',
            plugins: {
                legend: {
                    display:  true,
                    position: 'bottom',
                    labels: {
                        color:     CHART_DEFAULTS.color.body,
                        padding:   16,
                        font:      { size: 14 },
                        boxWidth:  12,
                        boxHeight: 12,
                    }
                },
                tooltip: chartTooltip()
            }
        }
    });
    return rememberChart(chart);
}

// ── Helpers ───────────────────────────────────────────────

function destroyCharts(ids) {
    if (typeof Chart === 'undefined') return;
    ids.forEach(id => {
        const existing = Chart.getChart(id);
        if (existing) { themedCharts.delete(existing); existing.destroy(); }
    });
}

function showStatsError(message) {
    const el = document.getElementById('stats-loading');
    if (!el) return;

    const icon = document.createElement('i');
    icon.className = 'fas fa-exclamation-triangle text-3xl text-red-500 mb-4 block';
    const text = document.createElement('p');
    text.className = 'text-red-500 text-xs uppercase tracking-widest font-orbitron';
    text.textContent = String(message ?? 'Analytics could not be loaded.');
    el.replaceChildren(icon, text);
}

function setStatText(id, value) {
    const el = document.getElementById(id);
    if (el) el.innerText = value;
}

// ── Cache ─────────────────────────────────────────────────

const _statsCache = {
    teacher: { data: null, fetchedAt: 0, promise: null },
    admin: { data: null, fetchedAt: 0, promise: null },
};
const STATS_CACHE_TTL_MS = 60000;
let statsCacheGeneration = 0;

function clearStatsCache() {
    statsCacheGeneration++;
    Object.values(_statsCache).forEach(entry => {
        entry.data = null;
        entry.fetchedAt = 0;
        entry.promise = null;
    });
}

async function cachedStats(kind, url, { foreground = true } = {}) {
    const entry = _statsCache[kind];
    if (entry.data && Date.now() - entry.fetchedAt < STATS_CACHE_TTL_MS) return entry.data;
    if (entry.promise) return entry.promise;

    const requestedGeneration = statsCacheGeneration;
    const requestClient = foreground ? (globalThis.mathVerseForegroundFetch ?? fetch) : fetch;
    const request = requestClient(url, {
        cache: 'no-store',
        headers: { 'Accept': 'application/json' },
    })
        .then(async response => {
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return data;
        })
        .then(data => {
            if (requestedGeneration === statsCacheGeneration) {
                entry.data = data;
                entry.fetchedAt = Date.now();
            }
            return data;
        })
        .finally(() => {
            if (entry.promise === request) entry.promise = null;
        });
    entry.promise = request;

    return request;
}

// ── Teacher stats ─────────────────────────────────────────

async function loadTeacherStats(options = {}) {
    if (!isDashboardRole('teacher')) return;
    const loading = document.getElementById('stats-loading');
    const content = document.getElementById('stats-content');
    if (typeof Chart === 'undefined') {
        const ready = await waitForChartLibrary();
        if (!ready) {
            showStatsError('Analytics resources are unavailable. Please refresh the page.');
            return;
        }
    }
    applyChartDefaults();

    // Show spinner, hide content
    loading?.classList.remove('hidden');
    content?.classList.add('hidden');

    try {
        const data = await cachedStats('teacher', '/teacher/stats', options);
        // A request can finish after the user has moved away from Analytics.
        // Do not reveal or measure a chart inside that hidden parent panel.
        if (!isStatsSectionActive('teacher')) return;

        // Populate summary cards
        setStatText('stat-total-attempts', data.totalAttempts ?? '0');
        setStatText('stat-avg-accuracy', (data.avgAccuracy ?? 0) + '%');
        setStatText('stat-total-quizzes', data.totalQuizzes ?? '0');

        // Swap spinner for content
        loading?.classList.add('hidden');
        content?.classList.remove('hidden');

        // Draw only after the section is visible and has real dimensions.
        // A newer navigation/render supersedes an old scheduled response.
        scheduleStatsRender('teacher', ['chart-attempts', 'chart-distribution', 'chart-class-accuracy'], () => {

            buildLineChart(
                'chart-attempts',
                data.attemptsPerDay.map(d => d.date),
                data.attemptsPerDay.map(d => d.count),
                'Attempts',
                CHART_DEFAULTS.color.cyan
            );

            buildDoughnutChart(
                'chart-distribution',
                ['0–25%', '26–50%', '51–75%', '76–100%'],
                data.distribution,
                [
                    CHART_DEFAULTS.color.red,
                    CHART_DEFAULTS.color.orange,
                    CHART_DEFAULTS.color.cyan,
                    CHART_DEFAULTS.color.green,
                ]
            );

            if (data.classAccuracy && data.classAccuracy.length > 0) {
                buildBarChart(
                    'chart-class-accuracy',
                    data.classAccuracy.map(item => item.class_name),
                    data.classAccuracy.map(item => item.accuracy),
                    'Avg Accuracy %',
                    CHART_DEFAULTS.color.purple
                );
            } else {
                const canvas = document.getElementById('chart-class-accuracy');
                if (canvas) canvas.insertAdjacentHTML('afterend',
                    '<p class="text-slate-500 text-xs text-center mt-4 uppercase tracking-widest">No active class attempts yet.</p>'
                );
            }
        });

    } catch (err) {
        console.error('Teacher stats error:', err);
        _statsCache.teacher.data = null;
        _statsCache.teacher.fetchedAt = 0;
        showStatsError('Failed to load analytics. Please try again.');
    }
}

// ── Admin stats ───────────────────────────────────────────

async function loadAdminStats(options = {}) {
    if (!isDashboardRole('admin')) return;
    const loading = document.getElementById('stats-loading');
    const content = document.getElementById('stats-content');
    if (typeof Chart === 'undefined') {
        const ready = await waitForChartLibrary();
        if (!ready) {
            showStatsError('Analytics resources are unavailable. Please refresh the page.');
            return;
        }
    }
    applyChartDefaults();

    loading?.classList.remove('hidden');
    content?.classList.add('hidden');

    try {
        const data = await cachedStats('admin', '/admin/stats', options);
        // A request can finish after the user has moved away from Analytics.
        // Do not reveal or measure a chart inside that hidden parent panel.
        if (!isStatsSectionActive('admin')) return;

        setStatText('stat-total-attempts', data.totalAttempts ?? '0');
        setStatText('stat-avg-accuracy', (data.avgAccuracy ?? 0) + '%');
        setStatText('stat-total-users', data.totalUsers ?? '0');

        loading?.classList.add('hidden');
        content?.classList.remove('hidden');

        scheduleStatsRender('admin', ['chart-attempts', 'chart-registrations', 'chart-roles', 'chart-distribution'], () => {

            buildLineChart(
                'chart-attempts',
                data.attemptsPerDay.map(d => d.date),
                data.attemptsPerDay.map(d => d.count),
                'Attempts',
                CHART_DEFAULTS.color.cyan
            );

            buildLineChart(
                'chart-registrations',
                data.registrationsPerDay.map(d => d.date),
                data.registrationsPerDay.map(d => d.count),
                'Registrations',
                CHART_DEFAULTS.color.green
            );

            buildDoughnutChart(
                'chart-roles',
                ['Students', 'Teachers', 'Pending'],
                [
                    data.roleBreakdown.students,
                    data.roleBreakdown.teachers,
                    data.roleBreakdown.pending,
                ],
                [
                    CHART_DEFAULTS.color.cyan,
                    CHART_DEFAULTS.color.purple,
                    CHART_DEFAULTS.color.orange,
                ]
            );

            buildDoughnutChart(
                'chart-distribution',
                ['0–25%', '26–50%', '51–75%', '76–100%'],
                data.distribution,
                [
                    CHART_DEFAULTS.color.red,
                    CHART_DEFAULTS.color.orange,
                    CHART_DEFAULTS.color.cyan,
                    CHART_DEFAULTS.color.green,
                ]
            );
        });

    } catch (err) {
        console.error('Admin stats error:', err);
        _statsCache.admin.data = null;
        _statsCache.admin.fetchedAt = 0;
        showStatsError('Failed to load analytics. Please try again.');
    }
}

document.addEventListener('mathverse:before-navigate', () => {
    chartRenderVersion++;
    destroyCharts(REPORT_CHART_IDS);
});
document.addEventListener('mathverse:section-change', event => {
    // Local dashboard section navigation does not emit before-navigate.
    // Canceling here prevents Chart.js from keeping a hidden 0×0 canvas.
    if (event.detail?.section === 'stats') return;
    chartRenderVersion++;
    destroyCharts(REPORT_CHART_IDS);
});
document.addEventListener('mathverse:data-changed', clearStatsCache);
document.addEventListener('mathverse:themechange', () => {
    // Site colors do not recolor report charts. In particular, do not resize
    // or update canvases here: Chart.js can measure a hidden dashboard panel
    // as 0×0 and leave the next visible render blank.
    applyChartDefaults();
    pruneCharts();
});
document.addEventListener('mathverse:charts-ready', applyChartDefaults);
