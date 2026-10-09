import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const sharedSource = readFileSync(new URL('../../public/js/shared.js', import.meta.url), 'utf8');
const progressSource = sharedSource.slice(
    sharedSource.indexOf('const mathVerseProgressTokens'),
    sharedSource.indexOf('function _spawn()')
);

function progressFixture(fetchImplementation) {
    const classes = new Set();
    const main = { attributes: new Map(), setAttribute(name, value) { this.attributes.set(name, value); } };
    const body = {
        classList: {
            toggle(name, enabled) {
                if (enabled) classes.add(name);
                else classes.delete(name);
            },
            contains: name => classes.has(name),
        },
    };
    const document = {
        body,
        querySelector: selector => selector === 'main' ? main : null,
        addEventListener() {},
    };
    const window = {
        setTimeout: () => 1,
        clearTimeout() {},
        addEventListener() {},
    };
    const context = vm.createContext({
        document,
        window,
        fetch: fetchImplementation,
        HTMLFormElement: class HTMLFormElement {},
        URL,
        queueMicrotask,
    });
    vm.runInContext(progressSource, context);

    return { body, main, window };
}

test('foreground progress remains active until the response body is consumed', async () => {
    let resolveBody;
    const bodyResult = new Promise(resolve => { resolveBody = resolve; });
    const response = {
        body: {},
        ok: true,
        json: () => bodyResult,
    };
    const fixture = progressFixture(() => Promise.resolve(response));

    const wrapped = await fixture.window.mathVerseForegroundFetch('/stats');
    assert.equal(fixture.body.classList.contains('mathverse-navigating'), true);
    assert.equal(fixture.main.attributes.get('aria-busy'), 'true');

    const parsed = wrapped.json();
    await Promise.resolve();
    assert.equal(fixture.body.classList.contains('mathverse-navigating'), true);

    resolveBody({ total: 4 });
    assert.deepEqual(await parsed, { total: 4 });
    assert.equal(fixture.body.classList.contains('mathverse-navigating'), false);
    assert.equal(fixture.main.attributes.get('aria-busy'), 'false');
});

test('foreground progress clears when a request fails before a response', async () => {
    const fixture = progressFixture(() => Promise.reject(new Error('offline')));

    await assert.rejects(
        fixture.window.mathVerseForegroundFetch('/stats'),
        /offline/
    );

    assert.equal(fixture.body.classList.contains('mathverse-navigating'), false);
    assert.equal(fixture.main.attributes.get('aria-busy'), 'false');
});

test('overlapping operations keep progress visible until every token finishes', () => {
    const fixture = progressFixture(() => Promise.resolve({ body: null }));
    const finishFirst = fixture.window.MathVerseProgress.begin();
    const finishSecond = fixture.window.MathVerseProgress.begin();

    finishFirst();
    assert.equal(fixture.body.classList.contains('mathverse-navigating'), true);

    finishSecond();
    assert.equal(fixture.body.classList.contains('mathverse-navigating'), false);
});
