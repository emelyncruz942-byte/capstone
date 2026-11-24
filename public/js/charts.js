// Dashboard Analytics deliberately uses ordinary DOM and SVG rather than a
// canvas charting runtime. The Learning Hub has remained dependable with this
// model: it survives hidden dashboard sections, supports browser hover/focus
// natively, and does not depend on a separate rendering bundle loading first.
const REPORT_COLORS = {
    cyan: '#22d3ee',
    purple: '#a78bfa',
    pink: '#f472b6',
    green: '#4ade80',
    orange: '#fbbf24',
    red: '#fb7185',
    grid: '#2c3c54',
    text: '#c4d2e7',
    heading: '#f1f5f9',
    surface: '#111c2e',
};

const REPORT_CHART_IDS = [
    'chart-attempts',
    'chart-distribution',
    'chart-class-accuracy',
    'chart-registrations',
    'chart-roles',
];

const reportCharts = new Map();
let chartRenderVersion = 0;

// Kept as a no-op for dashboard scripts that may call it. The report renderer
// no longer relies on a global Chart.js instance.
function applyChartDefaults() {}

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

    if (typeof globalThis.requestAnimationFrame === 'function') {
        globalThis.requestAnimationFrame(() => globalThis.requestAnimationFrame(draw));
        return;
    }
    globalThis.setTimeout(draw, 0);
}

function createElement(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = String(text);
    return element;
}

function createSvgElement(tag, attributes = {}) {
    const element = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attributes).forEach(([name, value]) => element.setAttribute(name, String(value)));
    return element;
}

function finiteNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : 0;
}

function reportEntries(labels, values) {
    const labelList = Array.isArray(labels) ? labels : [];
    const valueList = Array.isArray(values) ? values : [];
    return labelList.map((label, index) => ({
        label: String(label ?? ''),
        value: finiteNumber(valueList[index]),
    }));
}

function formatReportValue(value, suffix = '') {
    const number = finiteNumber(value);
    const formatted = Number.isInteger(number)
        ? number.toLocaleString('en-US')
        : number.toLocaleString('en-US', { maximumFractionDigits: 1 });
    return `${formatted}${suffix}`;
}

function axisIndices(length, maximum = 7) {
    if (length <= maximum) return Array.from({ length }, (_, index) => index);
    const result = new Set([0, length - 1]);
    for (let index = 1; index < maximum - 1; index++) {
        result.add(Math.round((index * (length - 1)) / (maximum - 1)));
    }
    return [...result].sort((first, second) => first - second);
}

function clearReportChart(id) {
    const target = document.getElementById(id);
    if (target) {
        target.replaceChildren();
        target.classList.remove('report-chart-ready', 'report-line-chart', 'report-bar-chart', 'report-doughnut-chart');
        target.removeAttribute('role');
        target.removeAttribute('aria-label');
    }
    reportCharts.delete(id);
}

function prepareReportChart(target, type, title) {
    target.replaceChildren();
    target.classList.add('report-chart-ready', `report-${type}-chart`);
    target.setAttribute('role', 'group');
    target.setAttribute('aria-label', title);
}

function appendReportDetail(target, initialText) {
    const detail = createElement('p', 'report-chart-detail', initialText);
    detail.setAttribute('aria-live', 'off');
    target.append(detail);
    return detail;
}

function appendEmptyReport(target, message) {
    target.append(createElement('p', 'report-chart-empty', message));
}

function addPointInteraction(point, description, detail) {
    const show = () => { detail.textContent = description; };
    point.setAttribute('tabindex', '0');
    point.setAttribute('role', 'img');
    point.setAttribute('aria-label', description);
    point.setAttribute('pointer-events', 'all');
    const title = createSvgElement('title');
    title.textContent = description;
    point.append(title);
    point.addEventListener('pointerenter', show);
    point.addEventListener('focus', show);
    point.addEventListener('click', show);
    point.addEventListener('touchstart', show, { passive: true });
}

function lineMarkerIndices(entries, mode = 'auto') {
    const meaningful = entries
        .map((entry, index) => ({ entry, index }))
        .filter(({ entry, index }) => entry.value !== 0 || index === 0 || index === entries.length - 1)
        .map(({ index }) => index);

    if (mode === 'events') return new Set(meaningful);
    if (entries.length <= 45) return new Set(entries.map((_entry, index) => index));
    return new Set(meaningful);
}

function buildLineChart(chartId, labels, data, label, color, markerMode = 'auto') {
    const target = document.getElementById(chartId);
    if (!target) return null;

    const entries = reportEntries(labels, data);
    prepareReportChart(target, 'line', label);
    if (!entries.length) {
        appendEmptyReport(target, 'No recorded data is available yet.');
        return null;
    }

    const latest = entries[entries.length - 1];
    const detail = appendReportDetail(target, `${latest.label}: ${formatReportValue(latest.value)}`);
    const svg = createSvgElement('svg', {
        class: 'report-chart-svg', viewBox: '0 0 640 270', preserveAspectRatio: 'none',
        role: 'group', 'aria-label': label,
    });
    const left = 48, right = 18, top = 16, bottom = 48;
    const width = 640 - left - right, height = 270 - top - bottom;
    const maximum = Math.max(1, ...entries.map(entry => entry.value));
    const pointAt = (entry, index) => ({
        x: entries.length === 1 ? left + width / 2 : left + (index / (entries.length - 1)) * width,
        y: top + height - (entry.value / maximum) * height,
    });

    for (let tick = 0; tick <= 4; tick++) {
        const value = (maximum * tick) / 4;
        const y = top + height - (height * tick) / 4;
        svg.append(createSvgElement('line', {
            class: 'report-chart-grid-line', x1: left, x2: left + width, y1: y, y2: y,
        }));
        const tickLabel = createSvgElement('text', {
            class: 'report-chart-axis-label', x: left - 8, y: y + 4, 'text-anchor': 'end',
        });
        tickLabel.textContent = formatReportValue(value);
        svg.append(tickLabel);
    }

    const points = entries.map(pointAt);
    const linePath = points.map((point, index) => `${index ? 'L' : 'M'} ${point.x.toFixed(2)} ${point.y.toFixed(2)}`).join(' ');
    const areaPath = `M ${points[0].x.toFixed(2)} ${(top + height).toFixed(2)} ${points.map(point => `L ${point.x.toFixed(2)} ${point.y.toFixed(2)}`).join(' ')} L ${points[points.length - 1].x.toFixed(2)} ${(top + height).toFixed(2)} Z`;
    svg.append(createSvgElement('path', { class: 'report-chart-area', d: areaPath, fill: `${color}28` }));
    svg.append(createSvgElement('path', { class: 'report-chart-line', d: linePath, stroke: color }));

    const markers = lineMarkerIndices(entries, markerMode);
    entries.forEach((entry, index) => {
        const point = points[index];
        const description = `${entry.label}: ${formatReportValue(entry.value)}`;
        // Full history still has a hover/focus target on every day. Dense
        // registration histories no longer paint hundreds of zero-value dots
        // along the baseline, which made the Admin graph look scattered.
        if (markers.has(index)) {
            svg.append(createSvgElement('circle', {
                class: 'report-chart-point', cx: point.x, cy: point.y, r: 3.8, fill: color,
            }));
        }
        const hit = createSvgElement('circle', {
            class: 'report-chart-hit', cx: point.x, cy: point.y, r: 13, fill: 'rgba(0,0,0,0.001)',
        });
        addPointInteraction(hit, description, detail);
        svg.append(hit);
    });

    axisIndices(entries.length).forEach(index => {
        const point = points[index];
        const labelNode = createSvgElement('text', {
            class: 'report-chart-axis-label', x: point.x, y: top + height + 22,
            'text-anchor': 'end', transform: `rotate(-32 ${point.x} ${top + height + 22})`,
        });
        labelNode.textContent = entries[index].label;
        svg.append(labelNode);
    });

    target.append(svg);
    const chart = { id: chartId, target, type: 'line', entries };
    reportCharts.set(chartId, chart);
    return chart;
}

function buildBarChart(chartId, labels, data, label, color) {
    const target = document.getElementById(chartId);
    if (!target) return null;

    const entries = reportEntries(labels, data);
    prepareReportChart(target, 'bar', label);
    if (!entries.length) {
        appendEmptyReport(target, 'No active class attempts yet.');
        return null;
    }

    const latest = entries[entries.length - 1];
    const detail = appendReportDetail(target, `${latest.label}: ${formatReportValue(latest.value, '%')}`);
    const svg = createSvgElement('svg', {
        class: 'report-chart-svg', viewBox: '0 0 640 270', preserveAspectRatio: 'none',
        role: 'group', 'aria-label': label,
    });
    const left = 48, right = 18, top = 16, bottom = 52;
    const width = 640 - left - right, height = 270 - top - bottom;
    const maximum = 100;

    for (let tick = 0; tick <= 4; tick++) {
        const value = (maximum * tick) / 4;
        const y = top + height - (height * tick) / 4;
        svg.append(createSvgElement('line', {
            class: 'report-chart-grid-line', x1: left, x2: left + width, y1: y, y2: y,
        }));
        const tickLabel = createSvgElement('text', {
            class: 'report-chart-axis-label', x: left - 8, y: y + 4, 'text-anchor': 'end',
        });
        tickLabel.textContent = `${formatReportValue(value)}%`;
        svg.append(tickLabel);
    }

    const slot = width / entries.length;
    const barWidth = Math.max(10, Math.min(60, slot * 0.62));
    entries.forEach((entry, index) => {
        const value = Math.max(0, Math.min(maximum, entry.value));
        const barHeight = (value / maximum) * height;
        const x = left + index * slot + (slot - barWidth) / 2;
        const y = top + height - barHeight;
        const description = `${entry.label}: ${formatReportValue(entry.value, '%')}`;
        svg.append(createSvgElement('rect', {
            class: 'report-chart-bar', x, y, width: barWidth, height: barHeight, rx: 4, fill: color,
        }));
        const hit = createSvgElement('rect', {
            class: 'report-chart-hit', x: x - 6, y: top, width: barWidth + 12, height,
            fill: 'rgba(0,0,0,0.001)',
        });
        addPointInteraction(hit, description, detail);
        svg.append(hit);

        const labelNode = createSvgElement('text', {
            class: 'report-chart-axis-label', x: x + barWidth / 2, y: top + height + 23,
            'text-anchor': 'end', transform: `rotate(-32 ${x + barWidth / 2} ${top + height + 23})`,
        });
        labelNode.textContent = entry.label;
        svg.append(labelNode);
    });

    target.append(svg);
    const chart = { id: chartId, target, type: 'bar', entries };
    reportCharts.set(chartId, chart);
    return chart;
}

function polarPoint(centerX, centerY, radius, angle) {
    const radians = (angle - 90) * (Math.PI / 180);
    return { x: centerX + radius * Math.cos(radians), y: centerY + radius * Math.sin(radians) };
}

function doughnutSegmentPath(centerX, centerY, outerRadius, innerRadius, startAngle, endAngle) {
    const span = Math.min(359.999, Math.max(0.01, endAngle - startAngle));
    const outerStart = polarPoint(centerX, centerY, outerRadius, startAngle);
    const outerEnd = polarPoint(centerX, centerY, outerRadius, startAngle + span);
    const innerEnd = polarPoint(centerX, centerY, innerRadius, startAngle + span);
    const innerStart = polarPoint(centerX, centerY, innerRadius, startAngle);
    const largeArc = span > 180 ? 1 : 0;
    return [
        `M ${outerStart.x} ${outerStart.y}`,
        `A ${outerRadius} ${outerRadius} 0 ${largeArc} 1 ${outerEnd.x} ${outerEnd.y}`,
        `L ${innerEnd.x} ${innerEnd.y}`,
        `A ${innerRadius} ${innerRadius} 0 ${largeArc} 0 ${innerStart.x} ${innerStart.y}`,
        'Z',
    ].join(' ');
}

function renderDoughnutChart(chart) {
    const { target, labels, values, colors, hidden } = chart;
    prepareReportChart(target, 'doughnut', 'Distribution chart');
    const entries = reportEntries(labels, values).map((entry, index) => ({
        ...entry,
        index,
        color: colors[index % colors.length],
        hidden: hidden.has(index),
    }));
    if (!entries.length) {
        appendEmptyReport(target, 'No recorded data is available yet.');
        return;
    }

    const visible = entries.filter(entry => !entry.hidden && entry.value > 0);
    const total = visible.reduce((sum, entry) => sum + entry.value, 0);
    const firstVisible = visible[0];
    const detail = appendReportDetail(
        target,
        firstVisible
            ? `${firstVisible.label}: ${formatReportValue(firstVisible.value)} (${total ? formatReportValue((firstVisible.value / total) * 100, '%') : '0%'})`
            : 'No categories are selected.'
    );
    const layout = createElement('div', 'report-doughnut-layout');
    const svg = createSvgElement('svg', {
        class: 'report-doughnut-svg', viewBox: '0 0 260 260', role: 'group',
        'aria-label': `Distribution: ${visible.map(entry => `${entry.label} ${formatReportValue(entry.value)}`).join('; ') || 'no selected categories'}`,
    });
    const centerX = 130, centerY = 130, outerRadius = 92, innerRadius = 56;
    let angle = 0;

    if (total > 0) {
        visible.forEach(entry => {
            const span = (entry.value / total) * 360;
            const description = `${entry.label}: ${formatReportValue(entry.value)} (${formatReportValue((entry.value / total) * 100, '%')})`;
            const slice = createSvgElement('path', {
                class: 'report-doughnut-slice',
                d: doughnutSegmentPath(centerX, centerY, outerRadius, innerRadius, angle, angle + span),
                fill: entry.color,
            });
            addPointInteraction(slice, description, detail);
            svg.append(slice);
            angle += span;
        });
    } else {
        svg.append(createSvgElement('circle', {
            class: 'report-doughnut-empty-ring', cx: centerX, cy: centerY, r: outerRadius,
        }));
    }

    svg.append(createSvgElement('circle', {
        class: 'report-doughnut-center', cx: centerX, cy: centerY, r: innerRadius,
    }));
    const totalText = createSvgElement('text', {
        class: 'report-doughnut-total', x: centerX, y: centerY - 2, 'text-anchor': 'middle',
    });
    totalText.textContent = formatReportValue(total);
    svg.append(totalText);
    const totalLabel = createSvgElement('text', {
        class: 'report-doughnut-caption', x: centerX, y: centerY + 19, 'text-anchor': 'middle',
    });
    totalLabel.textContent = 'selected';
    svg.append(totalLabel);
    layout.append(svg);

    const legend = createElement('div', 'report-chart-legend');
    legend.setAttribute('aria-label', 'Distribution filters');
    entries.forEach(entry => {
        const button = createElement('button', 'report-legend-button');
        button.type = 'button';
        button.setAttribute('aria-pressed', String(!entry.hidden));
        button.setAttribute('aria-label', `${entry.hidden ? 'Show' : 'Hide'} ${entry.label}`);
        if (entry.hidden) button.classList.add('is-hidden');

        const swatch = createElement('span', 'report-legend-swatch');
        swatch.style.backgroundColor = entry.color;
        swatch.setAttribute('aria-hidden', 'true');
        const text = createElement('span', 'report-legend-label', `${entry.label} (${formatReportValue(entry.value)})`);
        button.append(swatch, text);
        const description = `${entry.label}: ${formatReportValue(entry.value)}${entry.hidden ? ' — hidden' : ''}`;
        const show = () => { detail.textContent = description; };
        button.addEventListener('pointerenter', show);
        button.addEventListener('focus', show);
        button.addEventListener('click', () => {
            if (hidden.has(entry.index)) hidden.delete(entry.index); else hidden.add(entry.index);
            renderDoughnutChart(chart);
        });
        legend.append(button);
    });
    layout.append(legend);
    target.append(layout);
}

function buildDoughnutChart(chartId, labels, data, colors) {
    const target = document.getElementById(chartId);
    if (!target) return null;
    const chart = { id: chartId, target, labels, values: data, colors, hidden: new Set() };
    reportCharts.set(chartId, chart);
    renderDoughnutChart(chart);
    return chart;
}

function destroyCharts(ids) {
    ids.forEach(clearReportChart);
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
    loading?.classList.remove('hidden');
    content?.classList.add('hidden');

    try {
        const data = await cachedStats('teacher', '/teacher/stats', options);
        if (!isStatsSectionActive('teacher')) return;
        setStatText('stat-total-attempts', data.totalAttempts ?? '0');
        setStatText('stat-avg-accuracy', (data.avgAccuracy ?? 0) + '%');
        setStatText('stat-total-quizzes', data.totalQuizzes ?? '0');
        loading?.classList.add('hidden');
        content?.classList.remove('hidden');

        scheduleStatsRender('teacher', ['chart-attempts', 'chart-distribution', 'chart-class-accuracy'], () => {
            buildLineChart('chart-attempts', (data.attemptsPerDay ?? []).map(day => day.date), (data.attemptsPerDay ?? []).map(day => day.count), 'Attempts per day', REPORT_COLORS.cyan);
            buildDoughnutChart('chart-distribution', ['0–25%', '26–50%', '51–75%', '76–100%'], data.distribution ?? [], [REPORT_COLORS.red, REPORT_COLORS.orange, REPORT_COLORS.cyan, REPORT_COLORS.green]);
            buildBarChart('chart-class-accuracy', (data.classAccuracy ?? []).map(item => item.class_name), (data.classAccuracy ?? []).map(item => item.accuracy), 'Average accuracy per active class', REPORT_COLORS.purple);
        });
    } catch (error) {
        console.error('Teacher stats error:', error);
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
    loading?.classList.remove('hidden');
    content?.classList.add('hidden');

    try {
        const data = await cachedStats('admin', '/admin/stats', options);
        if (!isStatsSectionActive('admin')) return;
        setStatText('stat-total-attempts', data.totalAttempts ?? '0');
        setStatText('stat-avg-accuracy', (data.avgAccuracy ?? 0) + '%');
        setStatText('stat-total-users', data.totalUsers ?? '0');
        loading?.classList.add('hidden');
        content?.classList.remove('hidden');

        scheduleStatsRender('admin', ['chart-attempts', 'chart-registrations', 'chart-roles', 'chart-distribution'], () => {
            buildLineChart('chart-attempts', (data.attemptsPerDay ?? []).map(day => day.date), (data.attemptsPerDay ?? []).map(day => day.count), 'Quiz attempts per day', REPORT_COLORS.cyan);
            buildLineChart('chart-registrations', (data.registrationsPerDay ?? []).map(day => day.date), (data.registrationsPerDay ?? []).map(day => day.count), 'New registrations per day', REPORT_COLORS.green, 'events');
            buildDoughnutChart('chart-roles', ['Students', 'Teachers', 'Pending'], [data.roleBreakdown?.students, data.roleBreakdown?.teachers, data.roleBreakdown?.pending], [REPORT_COLORS.cyan, REPORT_COLORS.purple, REPORT_COLORS.orange]);
            buildDoughnutChart('chart-distribution', ['0–25%', '26–50%', '51–75%', '76–100%'], data.distribution ?? [], [REPORT_COLORS.red, REPORT_COLORS.orange, REPORT_COLORS.cyan, REPORT_COLORS.green]);
        });
    } catch (error) {
        console.error('Admin stats error:', error);
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
    if (event.detail?.section === 'stats') return;
    chartRenderVersion++;
    destroyCharts(REPORT_CHART_IDS);
});
document.addEventListener('mathverse:data-changed', clearStatsCache);
