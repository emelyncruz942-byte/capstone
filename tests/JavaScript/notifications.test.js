import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/notifications.js', import.meta.url), 'utf8');

function fixture() {
    const listeners = new Map();
    const requests = [];
    const classes = new Set(['hidden']);
    const menu = {
        classList: {
            contains: name => classes.has(name),
            add: name => classes.add(name),
            toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
        },
        setAttribute() {},
    };
    const badge = { removed: false, remove() { this.removed = true; } };
    const label = { textContent: '4 unread' };
    const form = { action: '/notifications/read-all' };
    const toggle = {
        setAttribute() {},
        closest(selector) { return selector === '[data-notification-toggle]' ? this : root; },
    };
    const root = {
        dataset: { unreadCount: '4' },
        querySelector(selector) {
            return {
                '[data-notification-menu]': menu,
                '[data-notification-toggle]': toggle,
                '[data-notification-read-all]': form,
                '[data-notification-badge]': badge.removed ? null : badge,
                '[data-notification-unread-label]': label,
            }[selector] ?? null;
        },
        querySelectorAll() { return []; },
    };
    const document = {
        visibilityState: 'visible',
        getElementById: () => ({ id: 'dashboard-content' }),
        querySelectorAll: selector => selector === '[data-notification-root]' ? [root] : [],
        addEventListener(name, callback) {
            if (!listeners.has(name)) listeners.set(name, []);
            listeners.get(name).push(callback);
        },
        dispatchEvent(event) {
            for (const callback of listeners.get(event.type) || []) callback(event);
        },
    };
    const window = {
        addEventListener() {},
        setTimeout: () => 1,
        clearTimeout() {},
    };

    vm.runInNewContext(source, {
        window,
        document,
        navigator: { onLine: true },
        FormData: class { constructor(sourceForm) { this.form = sourceForm; } },
        CustomEvent: class { constructor(type, options = {}) { this.type = type; this.detail = options.detail; } },
        showToast() {},
        fetch(url, options) {
            requests.push({ url, options });
            return Promise.resolve({ ok: true, json: async () => ({ message: 'All notifications marked as read.' }) });
        },
    });

    return {
        badge,
        classes,
        label,
        requests,
        root,
        clickToggle() {
            document.dispatchEvent({
                type: 'click',
                target: toggle,
                stopPropagation() {},
            });
        },
    };
}

async function flush() {
    for (let index = 0; index < 10; index++) await Promise.resolve();
}

test('opening the notification bell marks all unread notifications as read', async () => {
    const f = fixture();

    f.clickToggle();
    await flush();

    assert.equal(f.classes.has('hidden'), false);
    assert.equal(f.requests.length, 1);
    assert.equal(f.requests[0].url, '/notifications/read-all');
    assert.equal(f.requests[0].options.method, 'POST');
    assert.equal(f.root.dataset.unreadCount, '0');
    assert.equal(f.badge.removed, true);
    assert.equal(f.label.textContent, 'All caught up');
});
