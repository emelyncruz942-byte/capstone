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
    const documentListeners = new Map();
    const windowListeners = new Map();
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
        addEventListener(type, callback) {
            const listeners = documentListeners.get(type) ?? [];
            listeners.push(callback);
            documentListeners.set(type, listeners);
        },
    };
    const window = {
        setTimeout: () => 1,
        clearTimeout() {},
        addEventListener(type, callback) {
            const listeners = windowListeners.get(type) ?? [];
            listeners.push(callback);
            windowListeners.set(type, listeners);
        },
        location: {
            href: 'https://mathverse.test/dashboard',
            origin: 'https://mathverse.test',
            pathname: '/dashboard',
            search: '',
            hash: '',
        },
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

    return {
        body,
        main,
        window,
        dispatchDocument(type, event = {}) {
            for (const callback of documentListeners.get(type) ?? []) callback(event);
        },
        dispatchWindow(type, event = {}) {
            for (const callback of windowListeners.get(type) ?? []) callback(event);
        },
    };
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

test('view cleanup leaves a mutation token active', () => {
    const fixture = progressFixture(() => Promise.resolve({ body: null }));
    const finishView = fixture.window.MathVerseProgress.begin({ scope: 'view' });
    const finishMutation = fixture.window.MathVerseProgress.begin({ scope: 'mutation' });

    fixture.dispatchDocument('mathverse:before-navigate');
    assert.equal(fixture.body.classList.contains('mathverse-navigating'), true);

    finishMutation();
    assert.equal(fixture.body.classList.contains('mathverse-navigating'), false);
    finishView();
});

test('pageshow clears every stale progress scope', () => {
    const fixture = progressFixture(() => Promise.resolve({ body: null }));
    fixture.window.MathVerseProgress.begin({ scope: 'view' });
    fixture.window.MathVerseProgress.begin({ scope: 'mutation' });

    fixture.dispatchWindow('pageshow', { persisted: true });

    assert.equal(fixture.body.classList.contains('mathverse-navigating'), false);
});

test('foreground fetch scopes GET work to the view and clears mutations on abort', () => {
    const fixture = progressFixture(() => new Promise(() => {}));
    const mutationController = new AbortController();

    void fixture.window.mathVerseForegroundFetch('/stats');
    void fixture.window.mathVerseForegroundFetch('/save', {
        method: 'POST',
        signal: mutationController.signal,
    });

    fixture.dispatchDocument('mathverse:before-navigate');
    assert.equal(fixture.body.classList.contains('mathverse-navigating'), true);

    mutationController.abort();
    assert.equal(fixture.body.classList.contains('mathverse-navigating'), false);
});

test('downloads do not leave the global progress bar running', async () => {
    const fixture = progressFixture(() => Promise.resolve({ body: null }));
    const click = href => fixture.dispatchDocument('click', {
        button: 0,
        metaKey: false,
        ctrlKey: false,
        shiftKey: false,
        altKey: false,
        defaultPrevented: false,
        target: {
            closest: selector => selector === 'a[href]'
                ? { href, target: '', hasAttribute: name => name === 'download' }
                : null,
        },
    });

    click('https://mathverse.test/downloads/mathverse.apk');
    await Promise.resolve();

    assert.equal(fixture.body.classList.contains('mathverse-navigating'), false);
});
