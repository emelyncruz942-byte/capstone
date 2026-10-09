import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const chartsSource = readFileSync(new URL('../../public/js/charts.js', import.meta.url), 'utf8');
const statsCacheSource = chartsSource.slice(
    chartsSource.indexOf('const _statsCache'),
    chartsSource.indexOf('async function loadTeacherStats')
);

test('background stats requests bypass the foreground progress wrapper', async () => {
    let plainRequests = 0;
    let foregroundRequests = 0;
    const response = () => Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ totalAttempts: 1 }),
    });
    const context = vm.createContext({
        fetch: () => { plainRequests += 1; return response(); },
        mathVerseForegroundFetch: () => { foregroundRequests += 1; return response(); },
    });
    vm.runInContext(statsCacheSource, context);

    await vm.runInContext("cachedStats('teacher', '/teacher/stats', { foreground: false })", context);
    await vm.runInContext("cachedStats('admin', '/admin/stats', { foreground: true })", context);

    assert.equal(plainRequests, 1);
    assert.equal(foregroundRequests, 1);
});

function dashboardFixture(filename, role, loaderName) {
    const source = readFileSync(new URL(`../../public/js/${filename}`, import.meta.url), 'utf8');
    const readyCallbacks = [];
    const calls = [];
    const dashboard = { dataset: { dashboardRole: role } };
    const document = {
        getElementById(id) {
            if (id === 'dashboard-content') return dashboard;
            if (id === 'sec-overview') return {};
            return null;
        },
        addEventListener() {},
    };
    const context = vm.createContext({
        document,
        window: { location: { search: '?section=stats' } },
        URLSearchParams,
        requestAnimationFrame: callback => callback(),
        onMathVerseReady: callback => readyCallbacks.push(callback),
        showSection: () => true,
        [loaderName]: options => calls.push(options),
    });
    vm.runInContext(source, context);
    return { calls, ready: readyCallbacks[0] };
}

for (const [filename, role, loaderName] of [
    ['teacher.js', 'teacher', 'loadTeacherStats'],
    ['admin.js', 'admin', 'loadAdminStats'],
]) {
    test(`${role} background page refresh keeps stats loading quiet`, () => {
        const fixture = dashboardFixture(filename, role, loaderName);

        fixture.ready({ detail: { background: true } });

        assert.equal(fixture.calls.length, 1);
        assert.equal(fixture.calls[0].foreground, false);
    });
}
