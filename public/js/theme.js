(() => {
    const storageKey = 'mathverse.theme';
    const root = document.documentElement;
    const lockedTheme = root.dataset.themeLock === 'dark' ? 'dark' : null;
    let current = 'dark';
    function synchronizeButtons() {
        document.querySelectorAll('[data-theme-toggle]').forEach(button => {
            const isLight = current === 'light';
            const nextLabel = isLight ? 'Dark mode' : 'Light mode';
            button.setAttribute('aria-pressed', String(isLight));
            button.setAttribute('aria-label', `Switch to ${nextLabel.toLowerCase()}`);
            button.setAttribute('title', `Switch to ${nextLabel.toLowerCase()}`);
            const label = button.querySelector('[data-theme-label]');
            const icon = button.querySelector('[data-theme-icon]');
            if (label) label.textContent = nextLabel;
            if (icon) icon.className = `fas ${isLight ? 'fa-moon' : 'fa-sun'}`;
        });
    }
    function apply(value, persist = false) {
        current = lockedTheme || (value === 'light' ? 'light' : 'dark');
        root.dataset.theme = current;
        root.style.colorScheme = current;
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', current === 'light' ? '#edf5fb' : '#05070d');
        document.querySelector('meta[name="color-scheme"]')?.setAttribute('content', current);
        if (persist && !lockedTheme) {
            try { window.localStorage.setItem(storageKey, current); } catch { /* Private browsing can deny preference storage. */ }
        }
        synchronizeButtons();
        document.dispatchEvent(new CustomEvent('mathverse:themechange', { detail: { theme: current } }));
        return current;
    }
    let saved = lockedTheme || 'dark';
    if (!lockedTheme) {
        try { saved = window.localStorage.getItem(storageKey) || 'dark'; } catch { /* The original dark design remains the fallback. */ }
    }
    // This file runs in the head, before CSS, so a saved theme does not flash.
    apply(saved);
    window.MathVerseTheme = Object.freeze({ get: () => current, set: value => apply(value, true), toggle: () => apply(current === 'dark' ? 'light' : 'dark', true) });
    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-theme-toggle]');
        if (!button) return;
        event.preventDefault(); window.MathVerseTheme.toggle();
    });
    document.addEventListener('DOMContentLoaded', synchronizeButtons);
    document.addEventListener('mathverse:page-ready', synchronizeButtons);
    document.addEventListener('mathverse:page-loaded', synchronizeButtons);
    window.addEventListener('pageshow', synchronizeButtons);
    window.addEventListener('storage', event => {
        if (event.key === storageKey) apply(event.newValue);
    });
})();
