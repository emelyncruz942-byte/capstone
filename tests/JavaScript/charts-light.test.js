import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/charts.js', import.meta.url), 'utf8');

function fixture({ reduceMotion = false } = {}) {
    const canvas = {
        attributes: new Map(),
        setAttribute(name, value) { this.attributes.set(name, value); },
    };
    function Chart(element, configuration) {
        this.canvas = element;
        this.config = configuration;
    }
    Chart.defaults = { font: {} };
    const context = vm.createContext({
        Chart,
        document: {
            getElementById: id => id === 'chart' ? canvas : null,
            addEventListener() {},
        },
        matchMedia: () => ({ matches: reduceMotion }),
    });
    vm.runInContext(source, context);
    return { context, canvas, Chart, palette: vm.runInContext('CHART_DEFAULTS.color', context) };
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

test('chart labels and data colors remain readable against the light surface', () => {
    const { context, palette, Chart } = fixture();
    context.applyChartDefaults();
    assert.ok(contrast(Chart.defaults.color, palette.surface) >= 4.5);
    assert.ok(contrast(palette.heading, palette.surface) >= 4.5);
    for (const name of ['cyan', 'purple', 'pink', 'green', 'orange', 'red']) {
        assert.ok(contrast(palette[name], palette.surface) >= 3, `${name} must remain visible on white`);
    }
    assert.match(Chart.defaults.font.family, /system-ui/);
    assert.ok(Chart.defaults.font.size >= 13);
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
    const { context, palette, canvas } = fixture();
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
        assert.equal(tooltip.backgroundColor, '#ffffff');
        assert.ok(contrast(tooltip.bodyColor, tooltip.backgroundColor) >= 4.5);
        assert.ok(contrast(tooltip.titleColor, tooltip.backgroundColor) >= 4.5);
    }
    const legend = charts[2].config.options.plugins.legend.labels;
    assert.ok(contrast(legend.color, '#ffffff') >= 4.5);
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
