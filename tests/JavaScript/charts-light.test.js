import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/charts.js', import.meta.url), 'utf8');
const baseStyles = readFileSync(new URL('../../public/css/style.css', import.meta.url), 'utf8');
const themeColors = readFileSync(new URL('../../public/css/theme-colors.css', import.meta.url), 'utf8');
const dashboardLayout = readFileSync(new URL('../../resources/views/layouts/dashboard.blade.php', import.meta.url), 'utf8');
const teacherDashboard = readFileSync(new URL('../../resources/views/teacher/dashboard.blade.php', import.meta.url), 'utf8');
const adminDashboard = readFileSync(new URL('../../resources/views/admin/dashboard.blade.php', import.meta.url), 'utf8');
const appLayout = readFileSync(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');

class FakeClassList {
    constructor(owner) {
        this.owner = owner;
        this.values = new Set();
    }

    replaceFrom(value) {
        this.values = new Set(String(value || '').split(/\s+/).filter(Boolean));
    }

    add(...names) { names.forEach(name => this.values.add(name)); }
    remove(...names) { names.forEach(name => this.values.delete(name)); }
    contains(name) { return this.values.has(name); }
}

class FakeElement {
    constructor(tagName) {
        this.tagName = String(tagName).toLowerCase();
        this.children = [];
        this.attributes = new Map();
        this.events = new Map();
        this.dataset = {};
        this.style = {};
        this.classList = new FakeClassList(this);
        this._className = '';
        this.textContent = '';
        this.innerText = '';
    }

    get className() { return this._className; }
    set className(value) {
        this._className = String(value || '');
        this.classList.replaceFrom(this._className);
    }

    get lastChild() { return this.children[this.children.length - 1] ?? null; }
    append(...nodes) { nodes.forEach(node => this.appendChild(node)); }
    appendChild(node) {
        if (node) {
            node.parentNode = this;
            this.children.push(node);
        }
        return node;
    }
    replaceChildren(...nodes) {
        this.children = [];
        this.textContent = '';
        this.append(...nodes);
    }
    setAttribute(name, value) {
        const text = String(value);
        this.attributes.set(name, text);
        if (name === 'class') this.className = text;
    }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    removeAttribute(name) { this.attributes.delete(name); }
    addEventListener(type, listener) {
        const listeners = this.events.get(type) ?? [];
        listeners.push(listener);
        this.events.set(type, listeners);
    }
    dispatch(type) {
        for (const listener of this.events.get(type) ?? []) listener({ type, target: this });
    }
}

function descendants(root, predicate) {
    const matches = [];
    for (const child of root.children) {
        if (predicate(child)) matches.push(child);
        matches.push(...descendants(child, predicate));
    }
    return matches;
}

function fixture(role = 'teacher') {
    const listeners = new Map();
    const elements = new Map();
    const frames = [];
    const addElement = (id, element = new FakeElement('div')) => {
        elements.set(id, element);
        return element;
    };
    const dashboard = addElement('dashboard-content');
    dashboard.dataset.dashboardRole = role;
    addElement('sec-stats');
    addElement('stats-content');
    addElement('stats-loading');
    addElement('stat-total-attempts');
    addElement('stat-avg-accuracy');
    addElement('stat-total-quizzes');
    addElement('stat-total-users');
    for (const id of ['chart', 'chart-attempts', 'chart-distribution', 'chart-class-accuracy', 'chart-registrations', 'chart-roles']) {
        addElement(id);
    }

    const document = {
        getElementById(id) { return elements.get(id) ?? null; },
        createElement(tag) { return new FakeElement(tag); },
        createElementNS(_namespace, tag) { return new FakeElement(tag); },
        addEventListener(type, listener) {
            const registered = listeners.get(type) ?? [];
            registered.push(listener);
            listeners.set(type, registered);
        },
        removeEventListener(type, listener) {
            listeners.set(type, (listeners.get(type) ?? []).filter(item => item !== listener));
        },
    };
    const context = vm.createContext({
        document,
        console: { error() {} },
        fetch() { throw new Error('Unexpected network request'); },
        setTimeout() { return 1; },
        clearTimeout() {},
        requestAnimationFrame(callback) { frames.push(callback); return frames.length; },
    });
    vm.runInContext(source, context);

    return {
        context,
        element: id => elements.get(id),
        emit(type, event = {}) { for (const listener of listeners.get(type) ?? []) listener(event); },
        flushFrames() { while (frames.length) frames.shift()(); },
    };
}

test('dashboard analytics uses an SVG/HTML renderer without the fragile Chart.js runtime', () => {
    assert.doesNotMatch(source, /new Chart\s*\(/);
    assert.doesNotMatch(source, /waitForChartLibrary/);
    assert.doesNotMatch(source, /typeof Chart/);
    assert.doesNotMatch(dashboardLayout, /@vite\('resources\/js\/chart\.js'\)/);
    assert.doesNotMatch(teacherDashboard, /<canvas\b/);
    assert.doesNotMatch(adminDashboard, /<canvas\b/);
    assert.match(teacherDashboard, /class="report-chart-slot"/);
    assert.match(adminDashboard, /class="report-chart-slot"/);
});

test('line reports retain all history and update their visible value on hover and focus', () => {
    const f = fixture();
    const chart = f.context.buildLineChart('chart', ['October', 'November', 'December'], [2, 9, 5], 'Attempts', '#22d3ee');
    const target = f.element('chart');
    const detail = descendants(target, element => element.classList.contains('report-chart-detail'))[0];
    const hits = descendants(target, element => element.classList.contains('report-chart-hit'));

    assert.equal(chart.entries.length, 3);
    assert.equal(hits.length, 3);
    assert.match(detail.textContent, /December: 5/);
    hits[1].dispatch('pointerenter');
    assert.match(detail.textContent, /November: 9/);
    hits[0].dispatch('focus');
    assert.match(detail.textContent, /October: 2/);
    assert.ok(descendants(target, element => element.tagName === 'svg').length === 1);
});

test('dense registration histories keep every hover target but draw dots only for meaningful registration days', () => {
    const f = fixture('admin');
    const labels = Array.from({ length: 90 }, (_value, index) => `Day ${index + 1}`);
    const counts = labels.map((_label, index) => index === 4 || index === 47 || index === 89 ? 1 : 0);
    f.context.buildLineChart('chart-registrations', labels, counts, 'New registrations per day', '#4ade80', 'events');

    const target = f.element('chart-registrations');
    const points = descendants(target, element => element.classList.contains('report-chart-point'));
    const hits = descendants(target, element => element.classList.contains('report-chart-hit'));
    assert.equal(points.length, 4, 'Two event markers plus the first and last endpoints remain visible.');
    assert.equal(hits.length, 90, 'Every day remains hoverable and keyboard reachable.');
    hits[30].dispatch('focus');
    const detail = descendants(target, element => element.classList.contains('report-chart-detail'))[0];
    assert.match(detail.textContent, /Day 31: 0/);
});

test('bar reports show values on hover and preserve percentage precision', () => {
    const f = fixture();
    const chart = f.context.buildBarChart('chart', ['Grade 4', 'Grade 5'], [64.5, 87], 'Accuracy', '#a78bfa');
    const target = f.element('chart');
    const detail = descendants(target, element => element.classList.contains('report-chart-detail'))[0];
    const hits = descendants(target, element => element.classList.contains('report-chart-hit'));

    assert.equal(chart.entries[0].value, 64.5);
    hits[0].dispatch('pointerenter');
    assert.match(detail.textContent, /Grade 4: 64.5%/);
    hits[1].dispatch('focus');
    assert.match(detail.textContent, /Grade 5: 87%/);
});

test('doughnut slices show their values and legend buttons truly filter categories', () => {
    const f = fixture();
    f.context.buildDoughnutChart('chart', ['Low', 'Middle', 'High'], [2, 5, 8], ['#fb7185', '#fbbf24', '#4ade80']);
    let target = f.element('chart');
    let detail = descendants(target, element => element.classList.contains('report-chart-detail'))[0];
    const slices = descendants(target, element => element.classList.contains('report-doughnut-slice'));
    let buttons = descendants(target, element => element.classList.contains('report-legend-button'));

    assert.equal(slices.length, 3);
    assert.equal(buttons.length, 3);
    slices[1].dispatch('pointerenter');
    assert.match(detail.textContent, /Middle: 5/);
    assert.equal(buttons[0].getAttribute('aria-pressed'), 'true');

    buttons[0].dispatch('click');
    target = f.element('chart');
    buttons = descendants(target, element => element.classList.contains('report-legend-button'));
    detail = descendants(target, element => element.classList.contains('report-chart-detail'))[0];
    assert.equal(buttons[0].getAttribute('aria-pressed'), 'false');
    assert.match(detail.textContent, /Middle: 5|High: 8/);
    assert.equal(vm.runInContext('reportCharts.get("chart").hidden.has(0)', f.context), true);

    buttons[0].dispatch('click');
    buttons = descendants(f.element('chart'), element => element.classList.contains('report-legend-button'));
    assert.equal(buttons[0].getAttribute('aria-pressed'), 'true');
});

test('empty reports render a clear message instead of a blank canvas', () => {
    const f = fixture();
    assert.equal(f.context.buildLineChart('chart', [], [], 'Attempts', '#22d3ee'), null);
    const empty = descendants(f.element('chart'), element => element.classList.contains('report-chart-empty'))[0];
    assert.match(empty.textContent, /No recorded data/);
});

test('a delayed analytics render is cancelled when its parent section is hidden', () => {
    const f = fixture();
    let renders = 0;
    f.context.scheduleStatsRender('teacher', ['chart'], () => { renders += 1; });
    f.element('sec-stats').classList.add('hidden');
    f.flushFrames();
    assert.equal(renders, 0);
});

test('leaving analytics clears rendered report markup and cancels queued work', () => {
    const f = fixture();
    f.context.buildLineChart('chart-attempts', ['Day 1'], [4], 'Attempts', '#22d3ee');
    let renders = 0;
    f.context.scheduleStatsRender('teacher', ['chart-attempts'], () => { renders += 1; });
    f.emit('mathverse:section-change', { detail: { section: 'overview' } });
    f.flushFrames();
    assert.equal(f.element('chart-attempts').children.length, 0);
    assert.equal(renders, 0);
});

test('reports retain a high-contrast dark data surface in either site theme', () => {
    assert.match(baseStyles, /\.report-chart-slot \{[^}]*background: #111c2e/);
    assert.match(baseStyles, /\.report-chart-detail \{[^}]*color: #f1f5f9/);
    assert.match(baseStyles, /\.report-legend-button:hover, \.report-legend-button:focus-visible/);
    assert.match(themeColors, /html\[data-theme="light"\] \.analytics-meter span/);
});

test('all site toasts center their icon, text, and close control vertically', () => {
    assert.match(appLayout, /global-toast fixed[^\n]*flex items-center/);
    assert.doesNotMatch(appLayout, /data-toast-icon[^\n]*mt-0\.5/);
    assert.match(baseStyles, /\.global-toast \{[^}]*align-items: center/);
});
