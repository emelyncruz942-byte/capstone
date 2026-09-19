import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const sharedSource = readFileSync(new URL('../../public/js/shared.js', import.meta.url), 'utf8');
const resetSource = readFileSync(new URL('../../public/js/password-reset.js', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../../public/css/style.css', import.meta.url), 'utf8');

function sharedFixture({ registration = false, url = 'https://mathmetaverse.space/student/dashboard?section=profile' } = {}) {
    const listeners = new Map();
    const readers = [];
    const toasts = [];
    const modals = [];
    const replacements = [];
    const location = new URL(url);
    location.replace = value => replacements.push(value);
    class Element {
        constructor(src = null) {
            this.dataset = {};
            this.hidden = false;
            this.isConnected = true;
            this.attributes = new Map(src === null ? [] : [['src', src]]);
            this.listeners = new Map();
            const classes = new Set();
            this.classList = {
                contains: name => classes.has(name),
                add: (...names) => names.forEach(name => classes.add(name)),
                remove: (...names) => names.forEach(name => classes.delete(name)),
                toggle(name, force) {
                    const add = force ?? !classes.has(name);
                    if (add) classes.add(name); else classes.delete(name);
                },
            };
        }
        get src() { return this.getAttribute('src') || ''; }
        set src(value) { this.setAttribute('src', value); }
        getAttribute(name) { return this.attributes.get(name) ?? null; }
        setAttribute(name, value) { this.attributes.set(name, value); }
        removeAttribute(name) { this.attributes.delete(name); }
        addEventListener(name, callback) { this.listeners.set(name, callback); }
        setCustomValidity(value) { this.validationMessage = value; }
    }
    const headers = [new Element('/saved-avatar.png'), new Element('/saved-avatar.png')];
    const preview = new Element(registration ? null : '/saved-avatar.png');
    preview.hidden = registration;
    const placeholder = registration ? new Element() : null;
    const form = new Element();
    form.querySelector = selector => selector === '[data-avatar-preview]' ? preview
        : selector === '[data-avatar-placeholder]' ? placeholder : null;
    const input = new Element();
    input.files = [];
    input.closest = selector => selector === 'form' ? form : null;
    Object.defineProperty(input, 'value', { set(value) { if (value === '') this.files = []; } });
    const document = {
        readyState: 'loading', title: 'MathVerse',
        getElementById: id => id === 'avatar-preview' ? preview : id === 'avatar-placeholder' ? placeholder : null,
        querySelector: () => null,
        querySelectorAll(selector) {
            if (selector === 'input[type="file"][name="avatar"]') return [input];
            if (selector === '[data-current-user-avatar], #avatar-preview') return [...headers, preview];
            return [];
        },
        addEventListener(name, callback) {
            if (!listeners.has(name)) listeners.set(name, []);
            listeners.get(name).push(callback);
        },
    };
    const window = {
        location, addEventListener() {},
        matchMedia: () => ({ matches: true }),
        setTimeout() {}, clearTimeout() {},
        history: { state: null, replaceState(state, title, path) { location.href = new URL(path, location).href; } },
    };
    const context = vm.createContext({
        document, window, URL, URLSearchParams, HTMLElement: Element,
        setInterval() {}, clearInterval() {}, setTimeout() {}, clearTimeout() {}, queueMicrotask,
        FileReader: class {
            constructor() { readers.push(this); }
            readAsDataURL(file) { this.file = file; }
        },
    });
    vm.runInContext(sharedSource, context);
    context.showToast = (message, error) => toasts.push({ message, error });
    context.openModal = id => modals.push(id);
    context.closeModal = () => {};
    for (const callback of listeners.get('DOMContentLoaded') || []) callback();
    return {
        context, input, form, headers, preview, placeholder, readers, toasts, modals, replacements, location,
        select(file) { input.files = file ? [file] : []; context.previewAvatar(input); },
        complete(index, data = `data:image/png;base64,fixture-${index}`) { readers[index].onload({ target: { result: data } }); },
    };
}

const photo = name => ({ name, size: 2000, type: 'image/png' });

test('selecting a profile photo changes only the unsaved form preview', () => {
    const f = sharedFixture();
    f.select(photo('draft.png'));
    f.complete(0);
    assert.equal(f.preview.src, 'data:image/png;base64,fixture-0');
    assert.deepEqual(f.headers.map(image => image.src), ['/saved-avatar.png', '/saved-avatar.png']);
});

test('registration photo fully replaces the default placeholder', () => {
    const f = sharedFixture({ registration: true });
    f.select(photo('registration.png'));
    f.complete(0);
    assert.equal(f.preview.hidden, false);
    assert.equal(f.placeholder.hidden, true);
    assert.equal(f.placeholder.classList.contains('hidden'), true);
    assert.deepEqual(f.headers.map(image => image.src), ['/saved-avatar.png', '/saved-avatar.png']);
});

test('clearing or resetting a draft restores the saved profile photo', async () => {
    const f = sharedFixture();
    f.select(photo('draft.png'));
    f.complete(0);
    f.select(null);
    assert.equal(f.preview.src, '/saved-avatar.png');
    f.select(photo('next.png'));
    f.complete(1);
    f.form.listeners.get('reset')();
    f.input.files = [];
    await Promise.resolve();
    assert.equal(f.preview.src, '/saved-avatar.png');
    assert.deepEqual(f.headers.map(image => image.src), ['/saved-avatar.png', '/saved-avatar.png']);
});

test('invalid drafts restore the previous photo and leave saved header images alone', () => {
    const f = sharedFixture();
    f.select(photo('valid.png'));
    f.complete(0);
    f.select({ name: 'too-large.png', size: 3 * 1024 * 1024, type: 'image/png' });
    assert.equal(f.preview.src, '/saved-avatar.png');
    assert.equal(f.input.validationMessage, 'The selected image must be 2 MB or less.');
    assert.equal(f.modals.at(-1), 'imageSizeModal');
    assert.deepEqual(f.headers.map(image => image.src), ['/saved-avatar.png', '/saved-avatar.png']);
});

test('a late image read cannot overwrite a newer selection or a disconnected form', () => {
    const f = sharedFixture();
    f.select(photo('first.png'));
    f.select(photo('second.png'));
    f.complete(1, 'second-draft');
    f.complete(0, 'stale-draft');
    assert.equal(f.preview.src, 'second-draft');
    f.select(photo('third.png'));
    f.input.isConnected = false;
    f.complete(2, 'disconnected-draft');
    assert.equal(f.preview.src, 'second-draft');
});

test('hidden avatar placeholders override icon display rules and images fill the circle', () => {
    assert.match(styles, /\[data-avatar-placeholder\]\[hidden\]\s*\{\s*display:\s*none\s*!important/);
    const imageRule = styles.match(/\[data-avatar-preview\]\s*\{([^}]*)\}/)?.[1];
    assert.match(imageRule, /position:\s*absolute/);
    assert.match(imageRule, /inset:\s*0/);
    assert.match(imageRule, /object-fit:\s*cover/);
});

function resetFixture(url, hasServerToken = false) {
    const location = new URL(url);
    const token = { value: '' };
    const tokenType = { value: 'token_hash' };
    const submit = { disabled: false };
    const callbacks = [];
    const toasts = [];
    const form = {
        dataset: { hasRecoveryToken: String(hasServerToken) },
        querySelector: selector => ({ '[name="token"]': token, '[name="token_type"]': tokenType, 'button[type="submit"]': submit })[selector],
    };
    vm.runInNewContext(resetSource, {
        URL, URLSearchParams,
        document: { title: 'Reset password', getElementById: () => form },
        window: { location, history: { state: null, replaceState(state, title, path) { location.href = new URL(path, location).href; } } },
        onMathVerseReady: callback => callbacks.push(callback),
        showToast: (message, error) => toasts.push({ message, error }),
        fetch() { throw new Error('A reset token must not be verified just by opening its link.'); },
    });
    callbacks[0]();
    return { location, token, tokenType, submit, callbacks, toasts };
}

for (const [format, suffix, expectedType] of [
    ['current token hash fragment', '#token_hash=fixture-hash&type=recovery', 'token_hash'],
    ['legacy token hash query', '?token_hash=fixture-hash&type=recovery', 'token_hash'],
    ['access token fragment', '#access_token=fixture-access&type=recovery', 'access_token'],
    ['legacy access token query', '?access_token=fixture-access&type=recovery', 'access_token'],
]) {
    test(`reset forms accept ${format} and scrub the credential before submission`, () => {
        const f = resetFixture(`https://mathmetaverse.space/reset-password${suffix}`);
        assert.equal(f.token.value, expectedType === 'token_hash' ? 'fixture-hash' : 'fixture-access');
        assert.equal(f.tokenType.value, expectedType);
        assert.equal(f.submit.disabled, false);
        assert.equal(f.location.href, 'https://mathmetaverse.space/reset-password');
        assert.equal(f.toasts.length, 0);
        f.callbacks[0]();
        assert.equal(f.submit.disabled, false);
        assert.equal(f.toasts.length, 0);
    });
}

test('reset retries can use the encrypted server session without exposing its token', () => {
    const f = resetFixture('https://mathmetaverse.space/reset-password', true);
    assert.equal(f.submit.disabled, false);
    assert.equal(f.token.value, '');
});

test('incomplete or expired reset links disable submission and remove credentials', () => {
    for (const suffix of ['', '?type=recovery', '?token_hash=fixture&error=expired&type=recovery', '#token_hash=fixture&type=email']) {
        const f = resetFixture(`https://mathmetaverse.space/reset-password${suffix}`);
        assert.equal(f.submit.disabled, true);
        assert.equal(f.toasts.length, 1);
        assert.equal(f.toasts[0].error, true);
        assert.equal(f.location.href, 'https://mathmetaverse.space/reset-password');
        assert.equal(f.token.value, '');
    }
});

test('reset cleanup preserves unrelated non-sensitive query parameters', () => {
    const f = resetFixture('https://mathmetaverse.space/reset-password?lang=en&token_hash=fixture-hash&type=recovery');
    assert.equal(f.location.search, '?lang=en');
    assert.equal(f.token.value, 'fixture-hash');
});

test('shared auth cleanup leaves dedicated reset and confirmation token capture alone', () => {
    for (const path of ['/reset-password', '/auth/confirm']) {
        const url = `https://mathmetaverse.space${path}?token_hash=fixture-hash&type=recovery`;
        const f = sharedFixture({ url });
        f.context.handleAuthConfirmationReturn();
        assert.equal(f.location.href, url);
        assert.equal(f.toasts.length, 0);
    }
});

test('legacy recovery returns on login are forwarded using a token fragment', () => {
    const f = sharedFixture({ url: 'https://mathmetaverse.space/?token_hash=fixture-hash&type=recovery' });
    f.context.handleAuthConfirmationReturn();
    const destination = new URL(f.replacements[0]);
    assert.equal(destination.pathname, '/reset-password');
    assert.equal(destination.search, '');
    assert.equal(new URLSearchParams(destination.hash.slice(1)).get('token_hash'), 'fixture-hash');
});
