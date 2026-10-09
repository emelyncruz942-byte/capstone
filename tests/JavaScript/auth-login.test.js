import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const authSource = readFileSync(new URL('../../public/js/auth.js', import.meta.url), 'utf8');

function loginFixture({ initialValues = ['', ''] } = {}) {
    const readyCallbacks = [];
    const timers = [];
    const intervals = [];
    const documentListeners = new Map();

    function input(value = '') {
        const listeners = new Map();
        return {
            value,
            dataset: {},
            autofilled: false,
            addEventListener(name, callback) {
                if (!listeners.has(name)) listeners.set(name, []);
                listeners.get(name).push(callback);
            },
            matches(selector) {
                return this.autofilled && [':autofill', ':-webkit-autofill', ':-moz-autofill'].includes(selector);
            },
            emit(name, properties = {}) {
                for (const callback of listeners.get(name) || []) {
                    callback({ target: this, isTrusted: true, inputType: undefined, ...properties });
                }
            },
        };
    }

    const username = input(initialValues[0]);
    const password = input(initialValues[1]);
    const submitter = { clicks: 0, click() { this.clicks += 1; } };
    const formListeners = new Map();
    const form = {
        dataset: {},
        isConnected: true,
        requestSubmitCount: 0,
        requestSubmitter: null,
        querySelector(selector) {
            return {
                '[data-login-credential="username"]': username,
                '[data-login-credential="password"]': password,
                'button[type="submit"]': submitter,
            }[selector] ?? null;
        },
        addEventListener(name, callback) {
            if (!formListeners.has(name)) formListeners.set(name, []);
            formListeners.get(name).push(callback);
        },
        checkValidity: () => true,
        requestSubmit(button) {
            this.requestSubmitCount += 1;
            this.requestSubmitter = button;
            for (const callback of formListeners.get('submit') || []) callback();
        },
    };
    const document = {
        visibilityState: 'visible',
        querySelector: selector => selector === '[data-login-form]' ? form : null,
        getElementById: () => null,
        addEventListener(name, callback) {
            if (!documentListeners.has(name)) documentListeners.set(name, []);
            documentListeners.get(name).push(callback);
        },
    };
    const window = {
        setTimeout(callback) { timers.push(callback); return timers.length; },
        clearTimeout() {},
        setInterval(callback) { intervals.push(callback); return intervals.length; },
        clearInterval() {},
        addEventListener() {},
    };
    const context = vm.createContext({
        document,
        window,
        setTimeout: window.setTimeout,
        clearTimeout: window.clearTimeout,
        onMathVerseReady: callback => readyCallbacks.push(callback),
    });
    vm.runInContext(authSource, context);
    readyCallbacks[0]();

    return {
        form,
        username,
        password,
        submitter,
        inspect() {
            for (const callback of [...timers.splice(0), ...intervals]) callback();
        },
    };
}

test('credentials restored on page load do not submit without user interaction', () => {
    const fixture = loginFixture({ initialValues: ['student@example.com', 'SavedPassword1!'] });
    fixture.username.autofilled = true;
    fixture.password.autofilled = true;

    fixture.inspect();

    assert.equal(fixture.form.requestSubmitCount, 0);
});

test('choosing a saved username and password after interaction submits the native form once', () => {
    const fixture = loginFixture();
    fixture.password.emit('pointerdown');
    fixture.username.value = 'student@example.com';
    fixture.password.value = 'SavedPassword1!';
    fixture.username.autofilled = true;
    fixture.password.autofilled = true;

    fixture.inspect();
    fixture.inspect();

    assert.equal(fixture.form.requestSubmitCount, 1);
    assert.equal(fixture.form.requestSubmitter, fixture.submitter);
    assert.equal(fixture.form.dataset.autofillLoginSubmitted, 'true');
});

test('a partially autofilled form is never submitted', () => {
    const fixture = loginFixture({ initialValues: ['', 'TypedPassword1!'] });
    fixture.username.emit('pointerdown');
    fixture.username.value = 'student@example.com';
    fixture.username.autofilled = true;

    fixture.inspect();

    assert.equal(fixture.form.requestSubmitCount, 0);
});

test('ordinary typing does not trigger automatic login', () => {
    const fixture = loginFixture();
    fixture.username.emit('keydown');
    fixture.username.value = 'student@example.com';
    fixture.username.emit('input');
    fixture.password.emit('keydown');
    fixture.password.value = 'TypedPassword1!';
    fixture.password.emit('input');

    fixture.inspect();

    assert.equal(fixture.form.requestSubmitCount, 0);
});

test('virtual-keyboard input is treated as typing rather than password-manager fill', () => {
    const fixture = loginFixture();
    fixture.username.value = 'student@example.com';
    fixture.username.emit('beforeinput', { inputType: 'insertText' });
    fixture.username.emit('input', { inputType: 'insertText' });
    fixture.password.value = 'TypedPassword1!';
    fixture.password.emit('beforeinput', { inputType: 'insertText' });
    fixture.password.emit('input', { inputType: 'insertText' });

    fixture.inspect();

    assert.equal(fixture.form.requestSubmitCount, 0);
});

test('password-manager value injection without input events is detected', () => {
    const fixture = loginFixture();
    fixture.password.emit('focus');
    fixture.username.value = 'student@example.com';
    fixture.password.value = 'SavedPassword1!';

    fixture.inspect();

    assert.equal(fixture.form.requestSubmitCount, 1);
});

test('extension password managers that emit synthetic input events still submit once', () => {
    const fixture = loginFixture();
    fixture.password.emit('focus');
    fixture.username.value = 'student@example.com';
    fixture.username.emit('input', { isTrusted: false });
    fixture.password.value = 'SavedPassword1!';
    fixture.password.emit('input', { isTrusted: false });

    fixture.inspect();
    fixture.inspect();

    assert.equal(fixture.form.requestSubmitCount, 1);
});

test('one ambiguous replacement is not mistaken for a saved credential choice', () => {
    const fixture = loginFixture({ initialValues: ['', 'SavedPassword1!'] });
    fixture.password.autofilled = true;
    fixture.username.emit('focus');
    fixture.username.value = 'student@example.com';
    fixture.username.emit('input', { inputType: 'insertReplacementText' });

    fixture.inspect();

    assert.equal(fixture.form.requestSubmitCount, 0);
});

test('initial autofill stays idle but a fresh saved credential choice can submit', () => {
    const fixture = loginFixture({
        initialValues: ['student@example.com', 'OldSavedPassword1!'],
    });
    fixture.username.autofilled = true;
    fixture.password.autofilled = true;
    fixture.inspect();
    assert.equal(fixture.form.requestSubmitCount, 0);

    fixture.password.emit('focus');
    fixture.username.value = 'other@example.com';
    fixture.username.emit('input', { inputType: 'insertReplacementText' });
    fixture.password.value = 'NewSavedPassword1!';
    fixture.password.emit('input', { inputType: 'insertReplacementText' });
    fixture.inspect();

    assert.equal(fixture.form.requestSubmitCount, 1);
});
