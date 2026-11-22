import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/auth-submission.js', import.meta.url), 'utf8');

function fixture(action = '/login') {
    const listeners = new Map();
    const lifecycle = new Map();
    const microtasks = [];
    const button = {
        disabled: false,
        formAction: 'https://mathmetaverse.space/', // Missing overrides must use the form's URL.
        hasAttribute: () => false,
        classList: { add() {}, remove() {} },
    };
    class Form {
        constructor() { this.action = action; this.method = 'POST'; this.dataset = {}; this.attributes = new Map(); this.valid = true; }
        checkValidity() { return this.valid; }
        querySelectorAll() { return [button]; }
        getAttribute(key) { return this.attributes.get(key) ?? null; }
        setAttribute(key, value) { this.attributes.set(key, value); }
        removeAttribute(key) { this.attributes.delete(key); }
    }
    const window = {
        location: new URL('https://mathmetaverse.space/'),
        addEventListener(name, callback) { lifecycle.set(name, callback); },
    };
    vm.runInNewContext(source, {
        window, URL, HTMLFormElement: Form, queueMicrotask: callback => microtasks.push(callback),
        document: { addEventListener(name, callback) { listeners.set(name, callback); } },
    });
    const form = new Form();
    return {
        form, button, window,
        submit() {
            const event = {
                target: form, submitter: button, defaultPrevented: false, stopped: false,
                preventDefault() { this.defaultPrevented = true; },
                stopImmediatePropagation() { this.stopped = true; },
            };
            listeners.get('submit')(event);
            return event;
        },
        flush() { while (microtasks.length) microtasks.shift()(); },
        restore() { lifecycle.get('pageshow')(); },
    };
}

for (const path of ['/login', '/register', '/forgot-password', '/update-password', '/auth/confirm', '/logout', '/change-password', '/change-email']) {
    test(`${path} accepts one request and blocks repeated clicks or Enter`, () => {
        const f = fixture(path);
        assert.equal(f.submit().defaultPrevented, false);
        const duplicate = f.submit();
        assert.equal(duplicate.defaultPrevented, true);
        assert.equal(duplicate.stopped, true);
        f.flush();
        assert.equal(f.button.disabled, true);
        assert.equal(f.form.getAttribute('aria-busy'), 'true');
    });
}

test('invalid forms remain editable and a valid correction can submit', () => {
    const f = fixture(); f.form.valid = false;
    f.submit(); f.flush();
    assert.equal(f.button.disabled, false);
    f.form.valid = true;
    assert.equal(f.submit().defaultPrevented, false);
    f.flush(); assert.equal(f.button.disabled, true);
});

test('client validation cancellation releases the lock', () => {
    const f = fixture('/register');
    f.submit().preventDefault(); f.flush();
    assert.equal(f.button.disabled, false);
    assert.equal(f.submit().defaultPrevented, false);
});

test('AJAX completion releases every control and allows retry', () => {
    const f = fixture('/change-password');
    const event = f.submit(); event.preventDefault();
    f.form.dataset.mathverseSubmitting = 'true'; f.flush();
    assert.equal(f.button.disabled, true);
    f.window.mathVerseReleaseAuthForm(f.form);
    assert.equal(f.button.disabled, false);
    assert.equal(f.submit().defaultPrevented, false);
});

test('browser back restores the original button and accessibility states', () => {
    const f = fixture(); f.submit(); f.flush(); f.restore();
    assert.equal(f.button.disabled, false);
    assert.equal(f.form.getAttribute('aria-busy'), null);
    assert.equal(f.submit().defaultPrevented, false);
});

test('buttons disabled before submission stay disabled after restoration', () => {
    const f = fixture(); f.button.disabled = true;
    f.submit(); f.flush(); f.restore();
    assert.equal(f.button.disabled, true);
});

test('non-authentication and external forms retain their normal behavior', () => {
    for (const path of ['/student/practice/answer', 'https://another.example/login']) {
        const f = fixture(path); f.submit(); f.flush();
        assert.equal(f.button.disabled, false);
        assert.equal(f.form.dataset.authSubmitting, undefined);
    }
});
