(() => {
    function initializePasswordReset() {
        const resetForm = document.getElementById('resetForm');
        if (!resetForm || resetForm.dataset.passwordResetReady === 'true') return;
        resetForm.dataset.passwordResetReady = 'true';

        const url = new URL(window.location.href);
        const fragmentParams = new URLSearchParams(url.hash.replace(/^#/, ''));
        // Prefer the current fragment-based templates, but also accept links
        // from older templates without exposing their token in rendered HTML.
        const tokenHash = fragmentParams.get('token_hash') || url.searchParams.get('token_hash');
        const accessToken = fragmentParams.get('access_token') || url.searchParams.get('access_token');
        const token = tokenHash || accessToken;
        const type = fragmentParams.get('type') || url.searchParams.get('type');
        const hasAuthError = ['error', 'error_code', 'error_description'].some(key =>
            fragmentParams.has(key) || url.searchParams.has(key));
        const hasServerToken = resetForm.dataset.hasRecoveryToken === 'true';
        const submit = resetForm.querySelector('button[type="submit"]');

        // Scrub credentials even when the link is invalid or expired. Never
        // verify/consume a one-time token until the password form is submitted.
        const cleanUrl = new URL(url.href);
        cleanUrl.hash = '';
        ['token', 'token_hash', 'access_token', 'refresh_token', 'code', 'type', 'error', 'error_code', 'error_description']
            .forEach(key => cleanUrl.searchParams.delete(key));
        window.history.replaceState(window.history.state, document.title,
            cleanUrl.pathname + (cleanUrl.searchParams.size ? `?${cleanUrl.searchParams.toString()}` : ''));

        if (hasAuthError || (type && type !== 'recovery')) {
            submit.disabled = true;
            showToast('This password reset link is invalid or expired. Request a new password reset email.', true);
            return;
        }
        if (!token && !hasServerToken) {
            submit.disabled = true;
            showToast('This reset link is incomplete. Request a new password reset email.', true);
            return;
        }
        if (token) {
            resetForm.querySelector('[name="token"]').value = token;
            resetForm.querySelector('[name="token_type"]').value = tokenHash ? 'token_hash' : 'access_token';
        }
        submit.disabled = false;
    }

    onMathVerseReady(initializePasswordReset);
})();
