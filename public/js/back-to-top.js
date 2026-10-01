(() => {
    const revealAt = 360;
    const reducedMotion = typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function button() {
        return document.querySelector('[data-back-to-top]');
    }

    function sync() {
        const control = button();
        if (!control) return;

        const visible = window.scrollY > revealAt;
        control.hidden = false;
        control.classList.toggle('is-visible', visible);
        control.setAttribute('aria-hidden', String(!visible));
        control.tabIndex = visible ? 0 : -1;
    }

    document.addEventListener('click', event => {
        const control = event.target.closest('[data-back-to-top]');
        if (!control) return;

        window.scrollTo({
            top: 0,
            behavior: reducedMotion ? 'auto' : 'smooth',
        });
    });

    window.addEventListener('scroll', sync, { passive: true });
    document.addEventListener('mathverse:page-ready', sync);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sync, { once: true });
    } else {
        sync();
    }

    window.MathVerseBackToTop = { sync };
})();
