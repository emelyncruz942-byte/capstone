import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { Blob, File } from 'node:buffer';

const source = readFileSync(new URL('../../public/js/avatar-cropper.js', import.meta.url), 'utf8');

function fixture({ width = 1200, height = 800, decodeFails = false } = {}) {
    const documentListeners = new Map();
    const windowListeners = new Map();
    const nodes = [];
    const draws = [];
    const revoked = [];
    let document;
    class Node {
        constructor(tag) {
            this.tagName = tag; this.children = []; this.listeners = new Map(); this.attributes = new Map();
            this.disabled = false; this.hidden = false; this.inert = false; this.isConnected = true; this.className = '';
            this.classList = {
                add: name => { this.className += ` ${name}`; },
                remove: name => { this.className = this.className.split(' ').filter(item => item !== name).join(' '); },
                contains: name => this.className.split(' ').includes(name),
            };
            nodes.push(this);
        }
        append(...children) { children.forEach(child => { child.parentNode = this; this.children.push(child); }); }
        remove() {
            if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(child => child !== this);
            const disconnect = node => { node.isConnected = false; node.children.forEach(disconnect); }; disconnect(this);
        }
        setAttribute(name, value) { this.attributes.set(name, value); }
        removeAttribute(name) { this.attributes.delete(name); }
        addEventListener(name, callback) { this.listeners.set(name, callback); }
        focus() { document.activeElement = this; }
        contains(node) { return node === this || this.children.some(child => child.contains(node)); }
        querySelectorAll() { return this.children.flatMap(child => [...(['button', 'input'].includes(child.tagName) || child.tabIndex === 0 ? [child] : []), ...child.querySelectorAll()]); }
        getBoundingClientRect() { return { width: 400 }; }
        setPointerCapture() {}
        getContext() { return { clearRect() {}, drawImage(...args) { draws.push(args); } }; }
        toBlob(callback, type) { callback(new Blob(['cropped-pixels'], { type })); }
        emit(type, args = {}) { this.listeners.get(type)?.({ target: this, preventDefault() {}, ...args }); }
    }
    document = {
        activeElement: null,
        body: new Node('body'),
        createElement: tag => new Node(tag),
        addEventListener(type, callback) { if (!documentListeners.has(type)) documentListeners.set(type, new Set()); documentListeners.get(type).add(callback); },
        removeEventListener(type, callback) { documentListeners.get(type)?.delete(callback); },
    };
    const oldFocus = new Node('button'); document.body.append(oldFocus); oldFocus.focus();
    const window = {
        addEventListener(type, callback) { if (!windowListeners.has(type)) windowListeners.set(type, new Set()); windowListeners.get(type).add(callback); },
        removeEventListener(type, callback) { windowListeners.get(type)?.delete(callback); },
    };
    const context = vm.createContext({
        window, document, Blob, File, Set, Date,
        URL: { createObjectURL: () => `blob:local-${nodes.length}`, revokeObjectURL: value => revoked.push(value) },
        Image: class {
            constructor() { this.naturalWidth = width; this.naturalHeight = height; }
            set src(value) { queueMicrotask(() => { if (decodeFails) this.onerror?.(); else this.onload?.(); }); }
        },
    });
    vm.runInContext(source, context);
    const find = className => nodes.find(node => node.isConnected && node.className.split(' ').includes(className));
    function key(value, target = document.activeElement, shiftKey = false) {
        let prevented = false;
        const event = { key: value, target, shiftKey, preventDefault() { prevented = true; }, stopImmediatePropagation() {} };
        for (const handler of documentListeners.get('keydown') || []) handler(event);
        return prevented;
    }
    return {
        api: window.MathVerseAvatarCropper, document, oldFocus, draws, revoked, nodes, find, key,
        flush: () => new Promise(resolve => setImmediate(resolve)),
        navigate: () => { for (const handler of documentListeners.get('mathverse:before-navigate') || []) handler(); },
    };
}

function file(type = 'image/jpeg') { return new File(['original'], 'my-photo.jpg', { type }); }

test('every image shape, zoom, and extreme pan stays inside source pixels with a square crop', () => {
    const { bounds, pan } = fixture().api.geometry;
    for (const [width, height] of [[4000, 3000], [3000, 4000], [1200, 1200], [8000, 200], [200, 8000], [1, 1]]) {
        for (const zoom of [-4, 1, 1.01, 2.4, 4, 9]) {
            for (const centerX of [-1e8, 0, width / 2, width, 1e8]) {
                for (const centerY of [-1e8, 0, height / 2, height, 1e8]) {
                    const result = bounds(width, height, zoom, centerX, centerY);
                    assert.ok(result.x >= -1e-8 && result.y >= -1e-8);
                    assert.ok(result.x + result.size <= width + 1e-8);
                    assert.ok(result.y + result.size <= height + 1e-8);
                    assert.ok(result.size > 0 && result.zoom >= 1 && result.zoom <= 4);
                    const dragged = pan({ width, height, ...result }, 1e7, -1e7, 320);
                    assert.ok(dragged.x >= 0 && dragged.y >= 0 && dragged.x + dragged.size <= width + 1e-8 && dragged.y + dragged.size <= height + 1e-8);
                }
            }
        }
    }
});

test('small photos export at their native square edge instead of being enlarged', async () => {
    const f = fixture({ width: 80, height: 160 });
    const pending = f.api.crop(file());
    await f.flush();
    const canvas = f.find('avatar-crop-canvas');
    assert.equal(canvas.width, 80);
    assert.equal(canvas.height, 80);
    assert.deepEqual(f.draws.at(-1).slice(-2), [80, 80]);
    f.find('avatar-crop-confirm').emit('click');
    assert.ok(await pending);
});

test('invalid dimensions and unsupported or oversized files fail without opening a dialog', async () => {
    const f = fixture();
    assert.throws(() => f.api.geometry.bounds(0, 1));
    assert.throws(() => f.api.geometry.bounds(1, Number.NaN));
    await assert.rejects(f.api.crop(new File(['x'], 'bad.svg', { type: 'image/svg+xml' })), /JPEG, PNG, or WebP/);
    await assert.rejects(f.api.crop(new File([new Uint8Array(2 * 1024 * 1024 + 1)], 'large.jpg', { type: 'image/jpeg' })), /2 MB/);
    assert.equal(f.find('avatar-crop-dialog'), undefined);
});

test('cancel and Escape restore focus, scroll state, background inert state, and release local image URLs', async () => {
    for (const cancelWithEscape of [false, true]) {
        const f = fixture();
        const result = f.api.crop(file()); await f.flush();
        assert.ok(f.document.body.classList.contains('avatar-crop-open'));
        assert.equal(f.oldFocus.inert, true);
        assert.equal(f.document.activeElement, f.find('avatar-crop-canvas'));
        if (cancelWithEscape) assert.equal(f.key('Escape'), true); else f.find('avatar-crop-cancel').emit('click');
        assert.equal(await result, null);
        assert.equal(f.document.activeElement, f.oldFocus);
        assert.equal(f.oldFocus.inert, false);
        assert.equal(f.document.body.classList.contains('avatar-crop-open'), false);
        assert.equal(f.revoked.length, 1);
    }
});

test('the dialog traps Tab and Shift+Tab without trapping the native range arrow keys', async () => {
    const f = fixture(); const result = f.api.crop(file()); await f.flush();
    const confirm = f.find('avatar-crop-confirm'), close = f.find('avatar-crop-close');
    confirm.focus(); assert.equal(f.key('Tab'), true); assert.equal(f.document.activeElement, close);
    close.focus(); assert.equal(f.key('Tab', close, true), true); assert.equal(f.document.activeElement, confirm);
    const zoom = f.find('avatar-crop-range'); zoom.focus(); assert.equal(f.key('ArrowLeft'), false);
    f.find('avatar-crop-cancel').emit('click'); await result;
});

test('dragging and keyboard movement render the same bounded square that is exported at 512 pixels', async () => {
    const f = fixture(); const result = f.api.crop(file()); await f.flush();
    const canvas = f.find('avatar-crop-canvas');
    assert.equal(canvas.width, 512); assert.equal(canvas.height, 512);
    const zoom = f.find('avatar-crop-range'); zoom.value = '2'; zoom.emit('input');
    canvas.emit('pointerdown', { button: 0, pointerId: 1, clientX: 0, clientY: 0 });
    canvas.emit('pointermove', { pointerId: 1, clientX: -100000, clientY: 100000 });
    canvas.emit('pointerup', { pointerId: 1 });
    const last = f.draws.at(-1);
    assert.equal(last[3], last[4]); assert.equal(last[5], 0); assert.equal(last[6], 0); assert.equal(last[7], 512); assert.equal(last[8], 512);
    assert.ok(last[1] >= 0 && last[2] >= 0 && last[1] + last[3] <= 1200 && last[2] + last[4] <= 800);
    assert.equal(f.key('ArrowLeft'), true);
    f.find('avatar-crop-confirm').emit('click');
    const cropped = await result;
    assert.ok(cropped instanceof File); assert.equal(cropped.type, 'image/jpeg'); assert.equal(cropped.name, 'my-photo-cropped.jpg'); assert.ok(cropped.size <= 2 * 1024 * 1024);
});

for (const [type, extension] of [['image/png', 'png'], ['image/webp', 'webp']]) {
    test(`cropped ${type} retains the actual encoded format and matching file extension`, async () => {
        const f = fixture(); const result = f.api.crop(file(type)); await f.flush();
        f.find('avatar-crop-confirm').emit('click'); const cropped = await result;
        assert.equal(cropped.type, type); assert.ok(cropped.name.endsWith(`.${extension}`));
    });
}

test('navigation or a newer file cancels the older editor without submitting a stale crop', async () => {
    const f = fixture(); const first = f.api.crop(file()); await f.flush();
    const second = f.api.crop(file()); assert.equal(await first, null); await f.flush();
    f.navigate(); assert.equal(await second, null);
    assert.equal(f.document.body.classList.contains('avatar-crop-open'), false);
    assert.equal(f.revoked.length, 2);
});

test('cancelling a replacement crop returns focus to the original photo control', async () => {
    const f = fixture(); const first = f.api.crop(file()); await f.flush();
    const second = f.api.crop(file()); await first; await f.flush();
    f.find('avatar-crop-cancel').emit('click'); assert.equal(await second, null);
    assert.equal(f.document.activeElement, f.oldFocus);
});

test('a decode failure cleans up and rejects with a useful photo error', async () => {
    const f = fixture({ decodeFails: true });
    await assert.rejects(f.api.crop(file()), /photo could not be opened/);
    assert.equal(f.revoked.length, 1); assert.equal(f.document.body.classList.contains('avatar-crop-open'), false);
});

test('an encoder error keeps the crop cancellable and restores the Use photo button', async () => {
    const f = fixture(); const result = f.api.crop(file()); await f.flush();
    f.find('avatar-crop-canvas').toBlob = () => { throw new Error('The photo encoder is unavailable.'); };
    f.find('avatar-crop-confirm').emit('click');
    assert.equal(f.find('avatar-crop-confirm').disabled, false);
    assert.equal(f.find('avatar-crop-error').hidden, false);
    assert.equal(f.find('avatar-crop-error').textContent, 'The photo encoder is unavailable.');
    f.find('avatar-crop-cancel').emit('click'); assert.equal(await result, null);
});
