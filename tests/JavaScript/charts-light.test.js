import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/charts.js', import.meta.url), 'utf8');
const themeColors = readFileSync(new URL('../../public/css/theme-colors.css', import.meta.url), 'utf8');

function fixture({ reduceMotion = false, theme = 'dark', chartAvailable = true } = {}) {
    const listeners = new Map();
    const documentElement = { dataset: { theme } };
    const canvas = {
        attributes: new Map(),
        setAttribute(name, value) { this.attributes.set(name, value); },
        getClientRects() { return [{}]; },
    };
    function Chart(element, configuration) {
        this.canvas = element;
        this.config = configuration;
        this.data = configuration.data;
        this.options = configuration.options;
        this.updates = [];
        this.update = mode => this.updates.push(mode);
        this.destroy = () => { this.canvas = null; };
        Chart.created.push(this);
    }
    Chart.defaults = { font: {} };
    Chart.created = [];
    Chart.getChart = () => Chart.created.find(chart => chart.canvas !== null);
    const contextValues = {
        document: {
            documentElement,
            getElementById: id => id === 'chart' ? canvas : null,
            addEventListener(name, listener) {
                const previous = listeners.get(name);
                listeners.set(name, previous ? event => { previous(event); listener(event); } : listener);
            },
            removeEventListener() {},
        },
        matchMedia: () => ({ matches: reduceMotion }),
        fetch() { throw new Error('Theme switching must never fetch reporting data'); },
        setTimeout: () => 1,
        clearTimeout() {},
    };
    if (chartAvailable) contextValues.Chart = Chart;
    const context = vm.createContext(contextValues);
    vm.runInContext(source, context);
    return { context, canvas, Chart, listeners, palette: vm.runInContext('CHART_DEFAULTS.color', context),
        changeTheme(value) { documentElement.dataset.theme = value; listeners.get('mathverse:themechange')({ detail: { theme: value } }); } };
}

function contrast(first, second) {
    const luminance = hex => {
        const channels = hex.replace('#', '').match(/../g).map(channel => parseInt(channel, 16) / 255);
        const linear = channels.map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4);
        return linear[0] * .2126 + linear[1] * .7152 + linear[2] * .0722;
    };
    const a = luminance(first), b = luminance(second);
    return (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
}

for (const theme of ['light', 'dark']) test(`charts keep the dark palette in ${theme} site mode`, () => {
    const { context, palette, Chart } = fixture({ theme });
    context.applyChartDefaults();
    assert.equal(palette.surface, '#111c2e');
    assert.ok(contrast(Chart.defaults.color, palette.surface) >= 4.5);
    assert.ok(contrast(palette.heading, palette.surface) >= 4.5);
    for (const name of ['cyan', 'purple', 'pink', 'green', 'orange', 'red']) {
        assert.ok(contrast(palette[name], palette.surface) >= 3, `${name} must remain visible in ${theme}`);
    }
    assert.match(Chart.defaults.font.family, /Rajdhani/);
    assert.ok(Chart.defaults.font.size >= 14);
});

for (const reduceMotion of [false, true]) {
    test(`charts respect reduced motion (${reduceMotion})`, () => {
        const { context, Chart } = fixture({ reduceMotion });
        context.applyChartDefaults();
        if (reduceMotion) assert.equal(Chart.defaults.animation, false);
        else assert.equal(Chart.defaults.animation.duration, 320);
    });
}

test('line, bar and doughnut charts retain their complete reporting data and responsive rendering', () => {
    const { context, palette, canvas } = fixture({ theme: 'light' });
    const labels = ['Grade 4', 'Grade 5', 'Grade 6'];
    const values = [25, 67, 90];
    const charts = [
        context.buildLineChart('chart', labels, values, 'Attempts', palette.cyan),
        context.buildBarChart('chart', labels, values, 'Accuracy', palette.purple),
        context.buildDoughnutChart('chart', labels, values, [palette.cyan, palette.green, palette.orange]),
    ];
    for (const chart of charts) {
        assert.equal(chart.config.data.labels, labels);
        assert.equal(chart.config.data.datasets[0].data, values);
        assert.equal(chart.config.options.responsive, true);
        const tooltip = chart.config.options.plugins.tooltip;
        assert.equal(tooltip.backgroundColor, '#111c2e');
        assert.ok(contrast(tooltip.bodyColor, tooltip.backgroundColor) >= 4.5);
        assert.ok(contrast(tooltip.titleColor, tooltip.backgroundColor) >= 4.5);
        assert.equal(tooltip.enabled, true);
        assert.equal(chart.config.options.interaction.mode, 'nearest');
        assert.equal(chart.config.options.interaction.intersect, false);
    }
    const legend = charts[2].config.options.plugins.legend.labels;
    assert.ok(contrast(legend.color, palette.surface) >= 4.5);
    assert.equal(canvas.attributes.get('role'), 'img');
    assert.match(canvas.attributes.get('aria-label'), /Grade 4: 25; Grade 5: 67; Grade 6: 90/);
    assert.equal(charts[1].config.options.plugins.tooltip.callbacks.label({ parsed: { y: 67 } }), ' 67%');
});

test('full history stays intact while the accessible overview and axis labels remain concise', () => {
    const { context, canvas, palette } = fixture();
    const labels = Array.from({ length: 70 }, (_, index) => `Day ${index + 1}`);
    const values = Array.from({ length: 70 }, (_, index) => index);
    const chart = context.buildLineChart('chart', labels, values, 'Attempts', palette.cyan);
    assert.equal(chart.config.data.labels.length, 70);
    assert.equal(chart.config.data.datasets[0].data.length, 70);
    assert.match(canvas.attributes.get('aria-label'), /70 reporting periods, from Day 1 to Day 70. Latest value: 69/);
    assert.ok(chart.config.options.scales.x.ticks.maxTicksLimit <= 8);
    assert.equal(chart.config.data.datasets[0].pointHitRadius, 16);
});

test('optional charts are skipped when their canvas is absent', () => {
    const { context, palette } = fixture();
    assert.equal(context.buildLineChart('absent', [], [], 'Attempts', palette.cyan), null);
    assert.equal(context.buildBarChart('absent', [], [], 'Accuracy', palette.purple), null);
    assert.equal(context.buildDoughnutChart('absent', [], [], []), null);
});

test('analytics waits for the Chart module instead of rendering a blank panel during a slow first load', async () => {
    const f = fixture({ chartAvailable: false });
    const ready = f.context.waitForChartLibrary();
    f.context.Chart = f.Chart;
    f.listeners.get('mathverse:charts-ready')();
    assert.equal(await ready, true);
    assert.equal(f.Chart.defaults.color, f.palette.text);
});

test('site theme changes leave existing charts on their stable dark palette', () => {
    const f = fixture({ theme: 'dark' });
    f.context.applyChartDefaults();
    const labels = ['October', 'November'], values = [12, 38];
    const line = f.context.buildLineChart('chart', labels, values, 'Attempts', f.palette.cyan);
    const bar = f.context.buildBarChart('chart', labels, values, 'Accuracy', f.palette.purple);
    const doughnut = f.context.buildDoughnutChart('chart', labels, values, [f.palette.cyan, f.palette.green]);
    const callback = bar.options.plugins.tooltip.callbacks.label;
    const geometry = chart => JSON.stringify({
        responsive: chart.options.responsive,
        maintainAspectRatio: chart.options.maintainAspectRatio,
        cutout: chart.options.cutout,
        borderWidth: chart.data.datasets[0].borderWidth,
        borderRadius: chart.data.datasets[0].borderRadius,
        pointRadius: chart.data.datasets[0].pointRadius,
        pointHoverRadius: chart.data.datasets[0].pointHoverRadius,
        hoverOffset: chart.data.datasets[0].hoverOffset,
        tension: chart.data.datasets[0].tension,
        legendPadding: chart.options.plugins.legend?.labels?.padding,
        legendBoxWidth: chart.options.plugins.legend?.labels?.boxWidth,
        tooltipPadding: chart.options.plugins.tooltip.padding,
        tooltipRadius: chart.options.plugins.tooltip.cornerRadius,
    });
    const originalGeometry = [line, bar, doughnut].map(geometry);
    f.changeTheme('light');
    const stablePalette = vm.runInContext('CHART_DEFAULTS.color', f.context);
    for (const chart of [line, bar, doughnut]) {
        assert.equal(chart.data.labels, labels);
        assert.equal(chart.data.datasets[0].data, values);
        assert.equal(chart.options.plugins.tooltip.backgroundColor, stablePalette.surface);
        assert.deepEqual(chart.updates, []);
    }
    assert.equal(stablePalette.surface, '#111c2e');
    assert.equal(line.data.datasets[0].borderColor, stablePalette.cyan);
    assert.equal(bar.data.datasets[0].borderColor, stablePalette.purple);
    assert.equal(doughnut.data.datasets[0].backgroundColor[0], stablePalette.cyan + '99');
    assert.equal(doughnut.data.datasets[0].backgroundColor[1], stablePalette.green + '99');
    assert.equal(doughnut.options.plugins.legend.labels.color, stablePalette.body);
    assert.equal(line.options.scales.x.ticks.color, stablePalette.text);
    assert.equal(line.options.scales.y.grid.color, stablePalette.grid);
    assert.equal(bar.options.plugins.tooltip.callbacks.label, callback);
    assert.equal(callback({ parsed: { y: 38 } }), ' 38%');
    assert.equal(f.Chart.created.length, 3);
    assert.deepEqual([line, bar, doughnut].map(geometry), originalGeometry);
    f.changeTheme('dark');
    assert.equal(line.data.datasets[0].borderColor, f.palette.cyan);
    assert.equal(line.options.plugins.tooltip.backgroundColor, f.palette.surface);
    assert.equal(line.updates.length, 0);
    assert.equal(line.data.datasets[0].data, values);
    assert.deepEqual([line, bar, doughnut].map(geometry), originalGeometry);
});

test('destroyed charts are forgotten and cannot be updated by later theme changes', () => {
    const f = fixture();
    const chart = f.context.buildLineChart('chart', ['Day 1'], [4], 'Attempts', f.palette.cyan);
    f.context.destroyCharts(['chart']);
    f.changeTheme('light');
    assert.equal(chart.updates.length, 0);
    assert.equal(vm.runInContext('themedCharts.size', f.context), 0);
});

test('detached canvases and charts destroyed by another component are pruned without repainting', () => {
    for (const reason of ['detached', 'destroyed']) {
        const f = fixture();
        const chart = f.context.buildLineChart('chart', ['Day 1'], [4], 'Attempts', f.palette.cyan);
        if (reason === 'detached') chart.canvas.isConnected = false;
        else chart.ctx = null;
        f.changeTheme('light');
        assert.equal(chart.updates.length, 0);
        assert.equal(vm.runInContext('themedCharts.size', f.context), 0);
    }
});

test('a hidden dashboard chart waits to repaint until its section is visible', () => {
    const f = fixture({ theme: 'dark' });
    const chart = f.context.buildBarChart('chart', ['Grade 4'], [64], 'Accuracy', f.palette.purple);
    const callback = chart.options.plugins.tooltip.callbacks.label;
    f.canvas.getClientRects = () => [];
    f.changeTheme('light');
    assert.deepEqual(chart.updates, []);
    assert.equal(chart.options.plugins.tooltip.callbacks.label, callback);
    assert.equal(chart.data.datasets[0].data[0], 64);

    f.canvas.getClientRects = () => [{}];
    f.context.requestAnimationFrame = callback => callback();
    // Dispatch the registered visibility hook rather than rebuilding another
    // chart; it must repaint the same data and tooltip callback.
    f.listeners.get('mathverse:section-change')();
    assert.deepEqual(chart.updates, ['none']);
    assert.equal(chart.options.plugins.tooltip.callbacks.label, callback);
    assert.equal(chart.options.plugins.tooltip.enabled, true);
    assert.equal(chart.data.datasets[0].borderColor, '#a78bfa');
    assert.equal(chart.data.datasets[0].data[0], 64);
});

test('Learning Hub data bars retain their dark-mode gradients in light mode', () => {
    assert.match(themeColors, /html\[data-theme="light"\] \.analytics-meter span \{ background: linear-gradient\(90deg, #fb7185, #facc15\); \}/);
    assert.match(themeColors, /html\[data-theme="light"\] \.analytics-day-bar \{ background: linear-gradient\(180deg, #22d3ee, #7c3aed\); \}/);
});
