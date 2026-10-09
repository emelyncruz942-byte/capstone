function swMod(mode) {
    const l = document.getElementById('loginMod');
    const r = document.getElementById('regMod');
    l.classList.remove('module-active');
    r.classList.remove('module-active');
    setTimeout(() => {
        if (mode === 'reg') {
            l.style.display = 'none';
            r.style.display = 'block';
            setTimeout(() => r.classList.add('module-active'), 10);
        } else {
            r.style.display = 'none';
            l.style.display = 'block';
            setTimeout(() => l.classList.add('module-active'), 10);
        }
    }, 200);
}

function openForgotModal() {
    document.getElementById('forgotModal').classList.remove('hidden');
    document.getElementById('main-content').classList.add('blur-bg');
}

function closeForgotModal() {
    document.getElementById('forgotModal').classList.add('hidden');
    document.getElementById('main-content').classList.remove('blur-bg');
}

function toggleGradeLevel(role) {
    const field = document.getElementById('grade-level-field');
    if (!field) return;
    if (role === 'student') {
        field.classList.remove('hidden');
    } else {
        field.classList.add('hidden');
    }
}

const savedCredentialAutofillSelectors = [':autofill', ':-webkit-autofill', ':-moz-autofill'];

function matchesNativeSavedCredentialAutofill(input) {
    if (!input?.value) return false;
    return savedCredentialAutofillSelectors.some(selector => {
        try {
            return input.matches(selector);
        } catch {
            return false;
        }
    });
}

function matchesSavedCredentialAutofill(input) {
    if (!input?.value || input.dataset.loginManuallyEdited === 'true') return false;
    return input.dataset.loginBrowserAutofilled === 'true'
        || matchesNativeSavedCredentialAutofill(input);
}

function setupSavedCredentialLogin() {
    const form = document.querySelector('[data-login-form]');
    if (!form || form.dataset.autofillLoginReady === 'true') return;

    const username = form.querySelector('[data-login-credential="username"]');
    const password = form.querySelector('[data-login-credential="password"]');
    if (!username || !password) return;

    form.dataset.autofillLoginReady = 'true';
    const credentials = [username, password];
    const lastValues = new Map(credentials.map(input => [input, input.value]));
    const pendingManualInputs = new WeakSet();
    // Never sign in merely because a browser restored credentials on page load.
    // The user must first interact with a credential field and then choose a
    // saved credential during this page visit.
    let freshSelectionRequired = true;
    let userEngaged = false;
    let stopped = false;
    let submitted = false;

    const markManualEdit = input => {
        input.dataset.loginManuallyEdited = 'true';
        delete input.dataset.loginBrowserAutofilled;
        lastValues.set(input, input.value);
    };
    const markBrowserAutofill = input => {
        delete input.dataset.loginManuallyEdited;
        input.dataset.loginBrowserAutofilled = 'true';
    };

    const stop = () => {
        if (stopped) return;
        stopped = true;
        window.clearInterval(detectionTimer);
    };

    const submitSavedCredentials = () => {
        if (stopped || submitted || !form.isConnected) {
            if (!form.isConnected) stop();
            return;
        }
        if (freshSelectionRequired) return;
        if (!credentials.every(matchesSavedCredentialAutofill)) return;
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;

        submitted = true;
        form.dataset.autofillLoginSubmitted = 'true';
        const submitter = form.querySelector('button[type="submit"]');
        if (typeof form.requestSubmit === 'function') form.requestSubmit(submitter || undefined);
        else submitter?.click();
    };

    const acceptFreshSavedCredentialSelection = () => {
        if (!userEngaged || !credentials.every(matchesSavedCredentialAutofill)) return;
        freshSelectionRequired = false;
        window.setTimeout(submitSavedCredentials, 0);
    };

    const inspectCredentialValues = () => {
        if (stopped || submitted || document.visibilityState === 'hidden') return;

        const changed = credentials.filter(input => input.value !== lastValues.get(input));
        if (changed.length) {
            const autofillCandidates = changed.filter(input =>
                input.value && input.dataset.loginManuallyEdited !== 'true'
            );
            const nativePair = credentials.every(matchesNativeSavedCredentialAutofill);
            const injectedPair = autofillCandidates.length === credentials.length
                && credentials.every(input => input.value);

            // Extension password managers commonly set both values and emit
            // synthetic events. Requiring a complete pair prevents a single
            // spell-check, writing suggestion, or accessibility edit from
            // being mistaken for a saved-credential selection.
            if (userEngaged && (nativePair || injectedPair)) {
                (nativePair ? credentials : autofillCandidates).forEach(markBrowserAutofill);
                freshSelectionRequired = false;
            } else if (userEngaged) {
                autofillCandidates.forEach(markManualEdit);
            }

            changed.forEach(input => lastValues.set(input, input.value));
        }

        submitSavedCredentials();
    };

    for (const input of credentials) {
        const expectManualInput = () => {
            pendingManualInputs.add(input);
            markManualEdit(input);
        };
        input.addEventListener('keydown', expectManualInput);
        input.addEventListener('paste', expectManualInput);
        input.addEventListener('drop', expectManualInput);
        input.addEventListener('beforeinput', event => {
            if (event.isTrusted && event.inputType && event.inputType !== 'insertReplacementText') {
                expectManualInput();
            }
        });
        input.addEventListener('focus', () => { userEngaged = true; });
        input.addEventListener('pointerdown', () => { userEngaged = true; });
        input.addEventListener('input', event => {
            const browserReplacement = event.isTrusted
                && [null, '', 'insertReplacementText'].includes(event.inputType ?? null);

            if (pendingManualInputs.has(input)) {
                pendingManualInputs.delete(input);
                markManualEdit(input);
            } else if (!event.isTrusted) {
                // Do not consume the changed value here. The detector can then
                // recognize a complete pair injected by extension managers.
                window.setTimeout(inspectCredentialValues, 0);
                return;
            } else if (browserReplacement && matchesNativeSavedCredentialAutofill(input)) {
                markBrowserAutofill(input);
                lastValues.set(input, input.value);
                acceptFreshSavedCredentialSelection();
            } else if (browserReplacement) {
                // Wait until the current input burst ends. A full two-field
                // change is a password-manager fill; a one-field change is not.
                window.setTimeout(inspectCredentialValues, 0);
                return;
            } else {
                markManualEdit(input);
            }

            window.setTimeout(submitSavedCredentials, 0);
        });
        input.addEventListener('change', () => window.setTimeout(inspectCredentialValues, 0));
    }

    form.addEventListener('submit', () => {
        submitted = true;
        stop();
    }, { once: true });
    window.addEventListener('pagehide', stop, { once: true });
    document.addEventListener('mathverse:before-navigate', stop, { once: true });

    const detectionTimer = window.setInterval(inspectCredentialValues, 300);
    window.setTimeout(inspectCredentialValues, 0);
}

onMathVerseReady(setupSavedCredentialLogin);
