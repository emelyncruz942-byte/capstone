(() => {
    function roots() {
        return [...document.querySelectorAll('[data-profile-root]')];
    }

    function closeAll(except = null) {
        roots().forEach(root => {
            if (root === except) return;
            root.querySelector('[data-profile-menu]')?.classList.remove('open');
            root.querySelector('[data-profile-menu]')?.setAttribute('aria-hidden', 'true');
            root.querySelector('[data-profile-toggle]')?.setAttribute('aria-expanded', 'false');
        });
    }

    document.addEventListener('click', event => {
        const toggle = event.target.closest('[data-profile-toggle]');
        if (toggle) {
            const root = toggle.closest('[data-profile-root]');
            const menu = root?.querySelector('[data-profile-menu]');
            if (!root || !menu) return;

            event.stopPropagation();
            const willOpen = !menu.classList.contains('open');
            closeAll(root);
            menu.classList.toggle('open', willOpen);
            menu.setAttribute('aria-hidden', String(!willOpen));
            toggle.setAttribute('aria-expanded', String(willOpen));
            if (willOpen) {
                document.dispatchEvent(new CustomEvent('mathverse:header-menu-open', {
                    detail: { kind: 'profile' },
                }));
            }
            return;
        }

        const menu = event.target.closest('[data-profile-menu]');
        if (menu) {
            event.stopPropagation();
            if (event.target.closest('a, button')) closeAll();
            return;
        }

        closeAll();
    });

    document.addEventListener('mathverse:header-menu-open', event => {
        if (event.detail?.kind !== 'profile') closeAll();
    });
    document.addEventListener('mathverse:before-navigate', () => closeAll());

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const openRoot = roots().find(root => root.querySelector('[data-profile-menu]')?.classList.contains('open'));
        closeAll();
        openRoot?.querySelector('[data-profile-toggle]')?.focus();
    });
    window.addEventListener('resize', () => closeAll());
    window.addEventListener('orientationchange', () => closeAll());
})();
