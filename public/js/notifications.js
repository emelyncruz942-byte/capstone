(() => {
    const fallbackPollMs = 120000;
    let lastRefreshAt = Date.now();
    let refreshPromise = null;
    let pollTimer = null;

    function roots() {
        return [...document.querySelectorAll('[data-notification-root]')];
    }

    function closeAll(except = null) {
        roots().forEach(root => {
            if (root === except) return;
            root.querySelector('[data-notification-menu]')?.classList.add('hidden');
            root.querySelector('[data-notification-menu]')?.setAttribute('aria-hidden', 'true');
            root.querySelector('[data-notification-toggle]')?.setAttribute('aria-expanded', 'false');
        });
    }

    function replaceRoots(html) {
        const template = document.createElement('template');
        template.innerHTML = String(html ?? '').trim();
        const replacement = template.content.querySelector('[data-notification-root]');
        if (!replacement) return false;

        roots().forEach(root => root.replaceWith(replacement.cloneNode(true)));
        return true;
    }

    async function refresh(force = false) {
        if (!document.getElementById('dashboard-content')) return false;
        if (document.visibilityState === 'hidden' || navigator.onLine === false) return false;
        if (!force && Date.now() - lastRefreshAt < 30000) return false;
        if (refreshPromise) return refreshPromise;

        refreshPromise = (async () => {
            const response = await fetch('/notifications/snapshot', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) throw new Error('Notification refresh failed.');
            const payload = await response.json();
            if (!replaceRoots(payload.html)) throw new Error('Notification refresh was incomplete.');
            lastRefreshAt = Date.now();
            return true;
        })().catch(() => false).finally(() => {
            refreshPromise = null;
        });

        return refreshPromise;
    }

    function schedulePoll() {
        window.clearTimeout(pollTimer);
        const jitter = Math.floor(Math.random() * 30000);
        pollTimer = window.setTimeout(async () => {
            await refresh();
            schedulePoll();
        }, fallbackPollMs + jitter);
    }

    document.addEventListener('click', event => {
        const toggle = event.target.closest('[data-notification-toggle]');
        if (toggle) {
            const root = toggle.closest('[data-notification-root]');
            const menu = root?.querySelector('[data-notification-menu]');
            if (!root || !menu) return;

            event.stopPropagation();
            const willOpen = menu.classList.contains('hidden');
            closeAll(root);
            menu.classList.toggle('hidden', !willOpen);
            menu.setAttribute('aria-hidden', String(!willOpen));
            toggle.setAttribute('aria-expanded', String(willOpen));
            if (willOpen) {
                document.dispatchEvent(new CustomEvent('mathverse:header-menu-open', {
                    detail: { kind: 'notifications' },
                }));
                void refresh();
            }
            return;
        }

        if (event.target.closest('[data-notification-menu]')) {
            event.stopPropagation();
            return;
        }

        closeAll();
    });

    document.addEventListener('submit', async event => {
        const form = event.target.closest('[data-notification-root] form');
        if (!form || event.defaultPrevented) return;

        event.preventDefault();
        const submitter = event.submitter;
        if (submitter) submitter.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(form),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'The notification could not be updated.');

            if (payload.action_url) {
                if (window.MathVerseNavigation) {
                    await window.MathVerseNavigation.navigate(payload.action_url);
                    void refresh(true);
                } else {
                    window.location.assign(payload.action_url);
                }
                return;
            }

            await refresh(true);
            if (payload.message) showToast(payload.message);
        } catch (error) {
            showToast(error.message || 'The notification could not be updated.', true);
        } finally {
            if (submitter?.isConnected) submitter.disabled = false;
        }
    });

    document.addEventListener('mathverse:header-menu-open', event => {
        if (event.detail?.kind !== 'notifications') closeAll();
    });
    document.addEventListener('mathverse:before-navigate', () => closeAll());
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const openRoot = roots().find(root => !root.querySelector('[data-notification-menu]')?.classList.contains('hidden'));
        closeAll();
        openRoot?.querySelector('[data-notification-toggle]')?.focus();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') void refresh();
    });
    window.addEventListener('online', () => void refresh(true));
    window.addEventListener('resize', () => closeAll());
    window.addEventListener('orientationchange', () => closeAll());
    navigator.serviceWorker?.addEventListener('message', event => {
        if (event.data?.type === 'mathverse:notifications-changed') void refresh(true);
    });

    window.MathVerseNotifications = {
        refresh,
        markFresh() {
            lastRefreshAt = Date.now();
        },
    };

    schedulePoll();
})();
