import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/back-to-top.js', import.meta.url), 'utf8');
const appLayout = readFileSync(new URL('../../resources/views/layouts/app.blade.php', import.meta.url), 'utf8');

test('the shared application layout includes the back-to-top control and behavior', () => {
    assert.match(appLayout, /data-back-to-top/);
    assert.match(appLayout, /js\/back-to-top\.js/);
});

test('the global back-to-top control appears after scrolling and returns smoothly', () => {
    const listeners = new Map();
    const classes = new Set();
    const scrolls = [];
    const control = {
        hidden: true,
        tabIndex: -1,
        classList: { toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); } },
        setAttribute(name, value) { this[name] = value; },
        closest(selector) { return selector === '[data-back-to-top]' ? this : null; },
    };
    const document = {
        readyState: 'complete',
        querySelector: selector => selector === '[data-back-to-top]' ? control : null,
        addEventListener(name, callback) {
            if (!listeners.has(name)) listeners.set(name, []);
            listeners.get(name).push(callback);
        },
        dispatchEvent(event) {
            for (const callback of listeners.get(event.type) || []) callback(event);
        },
    };
    const window = {
        scrollY: 0,
        matchMedia: () => ({ matches: false }),
        addEventListener(name, callback) {
            if (!listeners.has(name)) listeners.set(name, []);
            listeners.get(name).push(callback);
        },
        scrollTo(options) { scrolls.push(options); },
    };

    vm.runInNewContext(source, { window, document });
    assert.equal(control.hidden, false);
    assert.equal(classes.has('is-visible'), false);

    window.scrollY = 500;
    for (const callback of listeners.get('scroll') || []) callback();
    assert.equal(classes.has('is-visible'), true);
    assert.equal(control.tabIndex, 0);

    document.dispatchEvent({ type: 'click', target: control });
    assert.equal(scrolls.length, 1);
    assert.equal(scrolls[0].top, 0);
    assert.equal(scrolls[0].behavior, 'smooth');
});
