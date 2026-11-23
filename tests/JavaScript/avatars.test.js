import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/avatars.js', import.meta.url), 'utf8');
function fixture() {
    const ready = [], listeners = new Map();
    const document = { readyState: 'loading', documentElement: {}, querySelectorAll: () => [], addEventListener: (name, callback) => listeners.set(name, callback) };
    let observe;
    const window = { location: { origin: 'https://mathmetaverse.space' } };
    vm.runInNewContext(source, {
        document, window, URL, WeakSet, queueMicrotask,
        onMathVerseReady: callback => ready.push(callback),
        MutationObserver: class { constructor(callback) { observe = callback; } observe() {} },
    });
    return { avatar: window.mathVerseAvatar, ready, observe };
}

test('initials preserve Unicode, whitespace-separated names, and stable account colors', () => {
    const { avatar } = fixture();
    assert.equal(avatar.presentation({ first_name: ' Élodie ', last_name: 'Ñerez' }).initials, 'ÉÑ');
    assert.equal(avatar.presentation({ display_name: 'Maria  dela\tCruz' }).initials, 'MC');
    assert.equal(avatar.presentation({ name: '小 林' }).initials, '小林');
    assert.equal(avatar.presentation({}).initials, 'U');
    assert.equal(avatar.presentation({ id: 'account-42', first_name: 'Beth' }).tone, avatar.presentation({ id: 'account-42', first_name: 'Elizabeth' }).tone);
});

test('dynamic markup escapes names and URLs without inline event handlers', () => {
    const { avatar } = fixture();
    const html = avatar.markup({ first_name: '<script>alert(1)</script>', last_name: 'Cruz', avatar_url: 'https://example.com/photo.png?x="&y=2' });
    assert.doesNotMatch(html, /<script>|onerror=|onload=/u);
    assert.match(html, /&lt;script&gt;/u);
    assert.match(html, /x=&quot;&amp;y=2/u);
    assert.match(html, /data-avatar-initials/u);
});

test('missing default and unsafe URLs never become image requests', () => {
    const { avatar } = fixture();
    for (const avatar_url of [null, '', '/default.png', 'https://example.com/default.png?cache=1', '/images/default.png', 'javascript:alert(1)', 'data:image/svg+xml,x', '//other.example/photo.png', 'photo.png', 'https://user:pass@example.com/a.png', 'https://example.com\\photo.png']) {
        assert.doesNotMatch(avatar.markup({ name: 'Beth Brito', avatar_url }), /<img/u);
    }
    assert.match(avatar.markup({ name: 'Beth Brito', avatar_url: '/uploads/photo.png' }), /src="\/uploads\/photo.png"/u);
});

test('anonymous peer markup cannot reveal unrelated real-name or photo fields', () => {
    const { avatar } = fixture();
    // Callers pass the public projection, never the full private profile.
    const row = { display_name: 'Anonymous Student', first_name: 'Private', avatar_url: 'https://example.com/private.png' };
    const html = avatar.markup({ display_name: row.display_name });
    assert.match(html, /data-avatar-anonymous/u);
    assert.doesNotMatch(html, />AS<\/span>/u);
    assert.doesNotMatch(html, /Private|private\.png|<img/u);
});

function imageFixture({ cachedFailure = false } = {}) {
    const f = fixture();
    const events = new Map(), classes = new Set();
    const placeholder = { hidden: true, classList: { toggle: (name, value) => value ? classes.add(name) : classes.delete(name) } };
    const root = { querySelector: () => placeholder };
    let src = '/photo.png';
    const image = { dataset: {}, hidden: false, complete: cachedFailure, naturalWidth: 0, closest: () => root, getAttribute: () => src, addEventListener: (name, callback) => events.set(name, callback) };
    f.avatar.refresh({ querySelectorAll: selector => selector === '[data-user-avatar] img' ? [image] : [] });
    return { ...f, image, placeholder, events, source: value => { src = value; } };
}

test('broken photo becomes initials and a later valid upload restores the photo', () => {
    const f = imageFixture();
    f.events.get('error')();
    assert.equal(f.image.hidden, true);
    assert.equal(f.placeholder.hidden, false);
    f.source('data:image/png;base64,new-draft'); f.image.hidden = false;
    f.avatar.syncImage(f.image);
    assert.equal(f.placeholder.hidden, true);
    f.events.get('load')();
    assert.equal(f.image.hidden, false);
    assert.equal(f.placeholder.hidden, true);
});

test('repeated dashboard ready events do not bind duplicate photo handlers', () => {
    const f = imageFixture();
    const error = new Error('bound only once');
    // A second ready event must not attach duplicate error listeners.
    f.image.addEventListener = () => { throw error; };
    f.avatar.refresh({ querySelectorAll: selector => selector === '[data-user-avatar] img' ? [f.image] : [] });
    f.events.get('error')();
    assert.equal(f.placeholder.hidden, false);
});

test('already-failed cached photos immediately show initials', () => {
    const f = imageFixture({ cachedFailure: true });
    assert.equal(f.image.hidden, true);
    assert.equal(f.placeholder.hidden, false);
});
