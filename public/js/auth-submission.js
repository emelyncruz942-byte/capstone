(() => {
    if (window.mathVerseReleaseAuthForm) return;

    const paths = new Set([
        '/login', '/register', '/forgot-password', '/update-password',
        '/auth/confirm', '/logout', '/change-password', '/change-email',
    ]);
    const pending = new Map();

    function release(form) {
        const state = pending.get(form);
        if (!state) return;
        pending.delete(form);
        delete form.dataset.authSubmitting;
        if (state.busy === null) form.removeAttribute('aria-busy');
        else form.setAttribute('aria-busy', state.busy);
        for (const [button, disabled] of state.buttons) {
            button.disabled = disabled;
            button.classList.remove('cursor-wait');
        }
    }

    window.mathVerseReleaseAuthForm = release;

    document.addEventListener('submit', event => {
        const form = event.target;
        if (event.defaultPrevented || !(form instanceof HTMLFormElement)) return;
        let url;
        try {
            url = new URL(event.submitter?.hasAttribute('formaction')
                ? event.submitter.formAction : form.action, window.location.href);
        }
        catch { return; }
        const method = String(event.submitter?.hasAttribute('formmethod')
            ? event.submitter.formMethod : (form.method || 'GET')).toUpperCase();
        if (method !== 'POST' || url.origin !== window.location.origin
            || !paths.has(url.pathname.replace(/\/$/, ''))) return;
        if (pending.has(form)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;

        const buttons = Array.from(form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"], input[type="image"]'));
        pending.set(form, {
            busy: form.getAttribute('aria-busy'),
            buttons: buttons.map(button => [button, button.disabled]),
        });
        form.dataset.authSubmitting = 'true';
        form.setAttribute('aria-busy', 'true');

        // Set the lock synchronously for keyboard/double-click submissions.
        // Let other submit handlers read FormData before disabling controls.
        queueMicrotask(() => {
            if (!pending.has(form)) return;
            if (event.defaultPrevented && form.dataset.mathverseSubmitting !== 'true') {
                release(form);
                return;
            }
            for (const button of buttons) {
                button.disabled = true;
                button.classList.add('cursor-wait');
            }
        });
    }, true);

    // Back/forward cache can restore the old disabled DOM after navigation.
    window.addEventListener('pageshow', () => {
        for (const form of pending.keys()) release(form);
    });
})();
