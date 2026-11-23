const CHART_DEFAULTS = {
    color: {
        cyan:   '#2563eb',
        purple: '#7c3aed',
        pink:   '#be185d',
        green:  '#0f766e',
        orange: '#b45309',
        red:    '#dc2626',
        grid:   '#e2e8f0',
        text:   '#64748b',
        heading: '#0f172a',
        surface: '#ffffff',
    }
};

function applyChartDefaults() {
    if (typeof Chart === 'undefined') return;
    Chart.defaults.color       = CHART_DEFAULTS.color.text;
    Chart.defaults.borderColor = CHART_DEFAULTS.color.grid;
    Chart.defaults.font.family = 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
    Chart.defaults.font.size   = 13;
    const reduceMotion = typeof globalThis.matchMedia === 'function'
        && globalThis.matchMedia('(prefers-reduced-motion: reduce)').matches;
    Chart.defaults.animation = reduceMotion ? false : { duration: 320 };
}

function chartTooltip(color = CHART_DEFAULTS.color.grid) {
    return {
        backgroundColor: CHART_DEFAULTS.color.surface,
        borderColor: color,
        borderWidth: 1,
        titleColor: CHART_DEFAULTS.color.heading,
        bodyColor: '#475569',
        titleFont: { size: 14, weight: '600' },
        bodyFont: { size: 13 },
        cornerRadius: 10,
        padding: 12,
        displayColors: false,
    };
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
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label,
                data,
                borderColor:          color,
                backgroundColor:      color + '12',
                borderWidth:          2.5,
                pointBackgroundColor: color,
                pointRadius:          labels.length > 45 ? 0 : 3,
                pointHoverRadius:     6,
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
}

function buildBarChart(canvasId, labels, data, label, color) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    describeChart(ctx, label, labels, data);
    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label,
                data,
                backgroundColor: color + 'cc',
                borderColor:     color,
                borderWidth:     1,
                borderRadius:    6,
                maxBarThickness: 56,
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
                        font:        { size: 12 },
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
}

function buildDoughnutChart(canvasId, labels, data, colors) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    describeChart(ctx, 'Distribution', labels, data);
    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: colors,
                borderColor:     CHART_DEFAULTS.color.surface,
                borderWidth:     3,
                hoverOffset:     5,
            }]
        },
        options: {
            responsive:          true,
            maintainAspectRatio: true,
            cutout:              '70%',
            plugins: {
                legend: {
                    display:  true,
                    position: 'bottom',
                    labels: {
                        color:     '#475569',
                        padding:   18,
                        font:      { size: 13 },
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth:  10,
                        boxHeight: 10,
                    }
                },
                tooltip: chartTooltip()
            }
        }
    });
}

// ── Helpers ───────────────────────────────────────────────

function destroyCharts(ids) {
    if (typeof Chart === 'undefined') return;
    ids.forEach(id => {
        const existing = Chart.getChart(id);
        if (existing) existing.destroy();
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
    const loading = document.getElementById('stats-loading');
    const content = document.getElementById('stats-content');
    if (typeof Chart === 'undefined') {
        showStatsError('Analytics resources are unavailable. Please refresh the page.');
        return;
    }

    // Show spinner, hide content
    loading?.classList.remove('hidden');
    content?.classList.add('hidden');

    try {
        const data = await cachedStats('teacher', '/teacher/stats', options);

        // Populate summary cards
        document.getElementById('stat-total-attempts').innerText = data.totalAttempts ?? '0';
        document.getElementById('stat-avg-accuracy').innerText   = (data.avgAccuracy ?? 0) + '%';
        document.getElementById('stat-total-quizzes').innerText  = data.totalQuizzes ?? '0';

        // Swap spinner for content
        loading?.classList.add('hidden');
        content?.classList.remove('hidden');

        // Draw charts after content is painted
        requestAnimationFrame(() => requestAnimationFrame(() => {
            destroyCharts(['chart-attempts', 'chart-distribution', 'chart-class-accuracy']);

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
        }));

    } catch (err) {
        console.error('Teacher stats error:', err);
        _statsCache.teacher.data = null;
        _statsCache.teacher.fetchedAt = 0;
        showStatsError('Failed to load analytics. Please try again.');
    }
}

// ── Admin stats ───────────────────────────────────────────

async function loadAdminStats(options = {}) {
    const loading = document.getElementById('stats-loading');
    const content = document.getElementById('stats-content');
    if (typeof Chart === 'undefined') {
        showStatsError('Analytics resources are unavailable. Please refresh the page.');
        return;
    }

    loading?.classList.remove('hidden');
    content?.classList.add('hidden');

    try {
        const data = await cachedStats('admin', '/admin/stats', options);

        document.getElementById('stat-total-attempts').innerText = data.totalAttempts ?? '0';
        document.getElementById('stat-avg-accuracy').innerText   = (data.avgAccuracy ?? 0) + '%';
        document.getElementById('stat-total-users').innerText    = data.totalUsers ?? '0';

        loading?.classList.add('hidden');
        content?.classList.remove('hidden');

        requestAnimationFrame(() => requestAnimationFrame(() => {
            destroyCharts(['chart-attempts', 'chart-registrations', 'chart-roles', 'chart-distribution']);

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
        }));

    } catch (err) {
        console.error('Admin stats error:', err);
        _statsCache.admin.data = null;
        _statsCache.admin.fetchedAt = 0;
        showStatsError('Failed to load analytics. Please try again.');
    }
}

document.addEventListener('mathverse:before-navigate', () => {
    destroyCharts([
        'chart-attempts',
        'chart-distribution',
        'chart-class-accuracy',
        'chart-registrations',
        'chart-roles',
    ]);
});
document.addEventListener('mathverse:data-changed', clearStatsCache);
