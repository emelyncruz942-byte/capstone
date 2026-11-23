(function () {
    'use strict';
    const boundImages = new WeakSet();
    function nameFor(person) {
        const clean = value => typeof value === 'string' ? value.trim().replace(/\s+/gu, ' ') : '';
        return `${clean(person.first_name)} ${clean(person.last_name)}`.trim()
            || clean(person.display_name || person.name) || 'User';
    }
    function presentation(person = {}) {
        const anonymous = person.is_anonymous === true || String(person.display_name || person.name || '').trim().toLowerCase() === 'anonymous student';
        const name = anonymous ? 'Anonymous Student' : nameFor(person);
        const words = name.split(/\s+/u);
        const initials = (Array.from(words[0])[0] + (words.length > 1 ? Array.from(words[words.length - 1])[0] : '')).toUpperCase();
        const identity = String(anonymous ? name : (person.id || person.email || name)).toLowerCase();
        let tone = 0;
        for (const letter of identity) tone = (tone + letter.codePointAt(0)) % 6;
        return { name, initials: anonymous ? '' : initials, tone, url: anonymous ? '' : photoUrl(person.avatar_url), anonymous };
    }
    function photoUrl(value) {
        if (typeof value !== 'string' || !value.trim() || /[\u0000-\u0020\u007f]/u.test(value.trim())) return '';
        const text = value.trim();
        if (text.includes('\\') || text.startsWith('//')) return '';
        try {
            const url = new URL(text, window.location.origin);
            if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) return '';
            if (['/default.png', '/images/default.png', '/default-avatar.png'].includes(url.pathname.toLowerCase())) return '';
            return text.startsWith('/') || /^https?:\/\//iu.test(text) ? text : '';
        } catch (_) { return ''; }
    }
    function syncImage(image) {
        const root = image.closest('[data-user-avatar]');
        if (!root) return;
        const placeholder = root.querySelector('[data-avatar-initials]');
        const raw = image.getAttribute('src') || '';
        const failed = image.dataset.avatarFailedSrc === raw;
        const hasImage = !!raw && !image.hidden && !failed;
        if (placeholder) { placeholder.hidden = hasImage; placeholder.classList.toggle('hidden', hasImage); }
        if (failed) image.hidden = true;
    }
    function bindImage(image) {
        if (boundImages.has(image)) return;
        boundImages.add(image);
        image.addEventListener('error', () => {
            image.dataset.avatarFailedSrc = image.getAttribute('src') || '';
            image.hidden = true;
            syncImage(image);
        });
        image.addEventListener('load', () => {
            delete image.dataset.avatarFailedSrc;
            image.hidden = false;
            syncImage(image);
        });
        if (image.complete && image.getAttribute('src') && image.naturalWidth === 0) {
            image.dataset.avatarFailedSrc = image.getAttribute('src'); image.hidden = true;
        }
        syncImage(image);
    }
    function refresh(scope = document) {
        scope.querySelectorAll('[data-user-avatar] img').forEach(bindImage);
        scope.querySelectorAll('[data-avatar-registration]').forEach(root => {
            const form = root.closest('form');
            if (!form || root.dataset.avatarNameBound === 'true') return;
            root.dataset.avatarNameBound = 'true';
            const update = () => {
                const data = presentation({ first_name: form.querySelector('[name="first_name"]')?.value || '', last_name: form.querySelector('[name="last_name"]')?.value || '' });
                const initials = root.querySelector('[data-avatar-initials]');
                if (initials) initials.textContent = data.initials;
                root.dataset.avatarName = data.name; root.setAttribute('aria-label', `${data.name} profile`);
                for (let tone = 0; tone < 6; tone++) root.classList.toggle(`user-avatar--tone-${tone}`, tone === data.tone);
            };
            form.addEventListener('input', update);
            form.addEventListener('reset', () => queueMicrotask(update));
            update();
        });
    }
    function escape(value) { return String(value).replace(/[&<>"']/gu, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character])); }
    function markup(person, size = 36) {
        const data = presentation(person);
        const dimension = [32, 36, 40, 44, 48, 64].includes(size) ? size : 36;
        return `<span class="user-avatar user-avatar--${dimension} user-avatar--tone-${data.tone}" data-user-avatar data-avatar-name="${escape(data.name)}" role="img" aria-label="${escape(data.name)} profile"><span data-avatar-initials aria-hidden="true"${data.url ? ' hidden' : ''}>${data.anonymous ? '<i class="fas fa-user" data-avatar-anonymous></i>' : escape(data.initials)}</span>${data.url ? `<img src="${escape(data.url)}" alt="" aria-hidden="true" width="${dimension}" height="${dimension}" loading="lazy" decoding="async">` : ''}</span>`;
    }
    function element(person, size = 36) {
        const data = presentation(person);
        const dimension = [32, 36, 40, 44, 48, 64].includes(size) ? size : 36;
        const root = document.createElement('span');
        root.className = `user-avatar user-avatar--${dimension} user-avatar--tone-${data.tone}`;
        root.setAttribute('data-user-avatar', ''); root.setAttribute('aria-hidden', 'true');
        const initials = document.createElement('span');
        initials.setAttribute('data-avatar-initials', '');
        if (data.anonymous) {
            const icon = document.createElement('i'); icon.className = 'fas fa-user'; icon.setAttribute('data-avatar-anonymous', ''); initials.append(icon);
        } else initials.textContent = data.initials;
        initials.hidden = !!data.url; root.append(initials);
        if (data.url) {
            const image = document.createElement('img'); image.src = data.url; image.alt = '';
            image.width = dimension; image.height = dimension; image.loading = 'lazy'; image.decoding = 'async'; root.append(image);
        }
        return root;
    }
    window.mathVerseAvatar = { presentation, markup, element, refresh, syncImage };
    if (typeof onMathVerseReady === 'function') onMathVerseReady(() => refresh());
    else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => refresh());
    else refresh();
    if (typeof MutationObserver === 'function') {
        const observer = new MutationObserver(records => {
            for (const record of records) {
                if (record.type === 'attributes' && record.target.matches('[data-user-avatar] img')) {
                    if (record.attributeName === 'src') delete record.target.dataset.avatarFailedSrc;
                    syncImage(record.target);
                } else for (const added of record.addedNodes || []) {
                    if (added.nodeType !== 1) continue;
                    if (added.matches('[data-user-avatar] img')) bindImage(added);
                    refresh(added);
                }
            }
        });
        observer.observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['src'] });
    }
})();
