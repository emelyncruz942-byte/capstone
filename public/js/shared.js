const _syms = ['+','−','×','÷','=','π','∑','√','Δ','∞','∫','f(x)','y²','x³'];

function onMathVerseReady(callback) {
    document.addEventListener('mathverse:page-ready', callback);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
        queueMicrotask(callback);
    }
}

window.onMathVerseReady = onMathVerseReady;

const mathVerseProgressTokens = new Map();

function syncMathVerseProgress() {
    const active = mathVerseProgressTokens.size > 0;
    document.body?.classList.toggle('mathverse-navigating', active);
    document.querySelector('main')?.setAttribute('aria-busy', String(active));
}

function beginMathVerseProgress(options = {}) {
    const token = Symbol('mathverse-request');
    const timeoutMs = Math.max(1000, Number(options.timeoutMs) || 45000);
    let finished = false;

    const finish = () => {
        if (finished) return;
        finished = true;
        const timer = mathVerseProgressTokens.get(token);
        if (timer) window.clearTimeout(timer);
        mathVerseProgressTokens.delete(token);
        syncMathVerseProgress();
    };

    mathVerseProgressTokens.set(token, window.setTimeout(finish, timeoutMs));
    syncMathVerseProgress();
    return finish;
}

function resetMathVerseProgress() {
    mathVerseProgressTokens.forEach(timer => window.clearTimeout(timer));
    mathVerseProgressTokens.clear();
    syncMathVerseProgress();
}

window.MathVerseProgress = {
    begin: beginMathVerseProgress,
    reset: resetMathVerseProgress,
    track(promise, options = {}) {
        const finish = beginMathVerseProgress(options);
        return Promise.resolve(promise).finally(finish);
    },
};

function mathVerseForegroundFetch(input, init = {}, progressOptions = {}) {
    const finish = beginMathVerseProgress(progressOptions);
    let request;
    try {
        request = fetch(input, init);
    } catch (error) {
        finish();
        throw error;
    }

    return Promise.resolve(request).then(response => {
        if (!response?.body) {
            finish();
            return response;
        }

        const bodyReaders = new Set(['arrayBuffer', 'blob', 'bytes', 'formData', 'json', 'text']);
        return new Proxy(response, {
            get(target, property) {
                if (bodyReaders.has(property) && typeof target[property] === 'function') {
                    return (...args) => {
                        try {
                            return Promise.resolve(target[property](...args)).finally(finish);
                        } catch (error) {
                            finish();
                            throw error;
                        }
                    };
                }

                const value = Reflect.get(target, property, target);
                return typeof value === 'function' ? value.bind(target) : value;
            },
        });
    }, error => {
        finish();
        throw error;
    });
}

window.mathVerseForegroundFetch = mathVerseForegroundFetch;

window.addEventListener('pageshow', resetMathVerseProgress);

function isMathVerseDownload(url, element) {
    const format = url.searchParams.get('format')?.toLowerCase();
    return element?.hasAttribute('download')
        || url.pathname.includes('/report/')
        || ['pdf', 'csv', 'xlsx', 'zip'].includes(format)
        || /\.(?:pdf|csv|xlsx|zip)$/i.test(url.pathname);
}

// Native page loads do not pass through the dashboard's seamless-navigation
// handler. Start the same progress bar for those forms and links too. The
// microtask lets client-side handlers cancel the event before we decide.
document.addEventListener('submit', event => {
    const form = event.target;
    queueMicrotask(() => {
        if (event.defaultPrevented || !(form instanceof HTMLFormElement)) return;
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;
        const submitter = event.submitter;
        const target = submitter?.formTarget || form.target;
        const method = String(submitter?.formMethod || form.method || 'GET').toUpperCase();
        if ((target && target !== '_self') || method === 'DIALOG') return;

        let destination;
        try {
            destination = new URL(submitter?.formAction || form.action || window.location.href, window.location.href);
        } catch {
            return;
        }
        if (destination.origin !== window.location.origin) return;
        if (isMathVerseDownload(destination, form)) {
            beginMathVerseProgress({ timeoutMs: 6000 });
            return;
        }
        beginMathVerseProgress({ timeoutMs: 45000 });
    });
}, true);

document.addEventListener('click', event => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const anchor = event.target.closest?.('a[href]');
    if (!anchor || anchor.target && anchor.target !== '_self') return;

    queueMicrotask(() => {
        if (event.defaultPrevented) return;
        let destination;
        try {
            destination = new URL(anchor.href, window.location.href);
        } catch {
            return;
        }
        if (destination.origin !== window.location.origin
            || destination.href === window.location.href
            || (destination.pathname === window.location.pathname
                && destination.search === window.location.search
                && destination.hash)) return;
        if (isMathVerseDownload(destination, anchor)) {
            beginMathVerseProgress({ timeoutMs: 6000 });
            return;
        }
        beginMathVerseProgress({ timeoutMs: 30000 });
    });
}, true);

function _spawn() {
    const c = document.getElementById('particle-container');
    if (!c || c.children.length > 10) return;
    const p      = document.createElement('div');
    p.className  = 'particle';
    p.innerText  = _syms[Math.floor(Math.random() * _syms.length)];
    p.style.left = Math.random() * 100 + 'vw';
    p.style.color = Math.random() > 0.5 ? '#00f2ff' : '#bc13fe';
    p.style.fontSize = (Math.random() * 15 + 15) + 'px';
    c.appendChild(p);
    setTimeout(() => p.remove(), 10000);
}
const reducedMotionQuery = typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)')
    : { matches: false };
let particleTimer = null;

function syncBackgroundMotion() {
    if (reducedMotionQuery.matches) {
        clearInterval(particleTimer);
        particleTimer = null;
        const particleContainer = document.getElementById('particle-container');
        if (particleContainer) particleContainer.textContent = '';
        return;
    }
    if (!particleTimer) particleTimer = setInterval(_spawn, 1500);
}

syncBackgroundMotion();
if (typeof reducedMotionQuery.addEventListener === 'function') {
    reducedMotionQuery.addEventListener('change', syncBackgroundMotion);
} else if (typeof reducedMotionQuery.addListener === 'function') {
    reducedMotionQuery.addListener(syncBackgroundMotion);
}

function showToast(message, isError = false) {
    const toast = document.getElementById('toast');
    if (!toast) return;
    const messageNode = document.getElementById('toast-msg');
    if (messageNode) messageNode.textContent = String(message ?? '');
    toast.setAttribute('role', isError ? 'alert' : 'status');
    toast.setAttribute('aria-live', isError ? 'assertive' : 'polite');
    toast.setAttribute('aria-hidden', 'false');
    toast.dataset.initialVisible = 'false';
    toast.classList.toggle('bg-red-500', isError);
    toast.classList.toggle('bg-cyan-500', !isError);
    toast.classList.toggle('text-white', isError);
    toast.classList.toggle('text-black', !isError);

    const icon = toast.querySelector('[data-toast-icon]');
    if (icon) {
        icon.classList.toggle('fa-circle-exclamation', isError);
        icon.classList.toggle('fa-circle-check', !isError);
    }

    toast.classList.remove('opacity-0', 'pointer-events-none');
    toast.classList.add('opacity-100');

    window.clearTimeout(window.mathVerseToastTimer);
    window.mathVerseToastTimer = window.setTimeout(hideToast, 6000);
}

function hideToast() {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.setAttribute('aria-hidden', 'true');
    toast.classList.remove('opacity-100');
    toast.classList.add('opacity-0', 'pointer-events-none');
}

function displayMathVerseDocumentFeedback(sourceDocument = document) {
    const sourceToast = sourceDocument.getElementById('toast');
    let displayedToast = false;
    if (sourceToast?.dataset.initialVisible === 'true') {
        showToast(
            sourceToast.querySelector('#toast-msg')?.textContent?.trim() || 'Done.',
            sourceToast.getAttribute('role') === 'alert'
        );
        sourceToast.dataset.initialVisible = 'false';
        displayedToast = true;
    }

    const sourceImageModal = sourceDocument.getElementById('imageSizeModal');
    if (sourceImageModal?.dataset.initialOpen === 'true') {
        const currentImageModal = document.getElementById('imageSizeModal');
        const sourceMessage = sourceImageModal.querySelector('#image-size-message')?.textContent;
        const currentMessage = currentImageModal?.querySelector('#image-size-message');
        if (sourceMessage && currentMessage) currentMessage.textContent = sourceMessage.trim();
        openModal('imageSizeModal');
        sourceImageModal.dataset.initialOpen = 'false';
    }

    if (displayedToast) {
        const url = new URL(window.location.href);
        if (url.searchParams.has('notice')) {
            url.searchParams.delete('notice');
            window.history.replaceState(
                window.history.state,
                document.title,
                url.pathname + (url.searchParams.size ? `?${url.searchParams.toString()}` : '') + url.hash
            );
        }
    }
}

window.displayMathVerseDocumentFeedback = displayMathVerseDocumentFeedback;
onMathVerseReady(() => displayMathVerseDocumentFeedback(document));

const mathVerseDelegatedActions = new Set([
    'addNewQuestion',
    'chooseAnotherAvatar',
    'closeForgotModal',
    'closeLobby',
    'closeModal',
    'confirmDelete',
    'confirmSuspend',
    'copyToClipboard',
    'hideToast',
    'loadQuizBuilder',
    'openAssignmentSettings',
    'openDeleteAssignment',
    'openDeleteQuizModal',
    'openForgotModal',
    'openLobby',
    'openModal',
    'openQuizAction',
    'openRemoveStudent',
    'openReportDeleteModal',
    'openRestoreQuizVersion',
    'openResults',
    'openSessionReport',
    'openAssignQuiz',
    'removeQuestion',
    'swMod',
    'tglPass',
    'toggleGradeLevel',
    'toggleQuizView',
    'toggleSidebar',
]);

function delegatedActionArguments(control) {
    if (!control.dataset.actionArgs) return [];

    try {
        const parsed = JSON.parse(control.dataset.actionArgs);
        return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
        console.error('Invalid MathVerse action arguments.', error);
        return [];
    }
}

document.addEventListener('click', event => {
    const control = event.target.closest('[data-action]');
    if (!control || control.disabled) return;

    event.preventDefault();
    const action = control.dataset.action;
    if (action === 'navigate') {
        const destination = new URL(control.dataset.destination || '/', window.location.origin);
        if (destination.origin === window.location.origin) {
            beginMathVerseProgress({ timeoutMs: 30000 });
            window.location.assign(destination.href);
        }
        return;
    }
    if (action === 'closeModalAndSubmit') {
        const [modalId, formId] = delegatedActionArguments(control);
        closeModal(modalId);
        document.getElementById(formId)?.requestSubmit();
        return;
    }
    if (!mathVerseDelegatedActions.has(action)) return;

    const handler = window[action];
    if (typeof handler === 'function') {
        const args = delegatedActionArguments(control);
        handler(...(control.hasAttribute('data-action-self') ? [control, ...args] : args));
    }
});

document.addEventListener('change', event => {
    const control = event.target.closest('[data-change-action]');
    if (!control) return;

    const action = control.dataset.changeAction;
    if (!mathVerseDelegatedActions.has(action)) return;

    const handler = window[action];
    if (typeof handler === 'function') handler(control.value);
});

function handleAuthConfirmationReturn() {
    const url = new URL(window.location.href);
    // Dedicated auth pages own fragment-token capture and cleanup.
    if (['/reset-password', '/auth/confirm'].includes(url.pathname)) return;
    const hashParams = new URLSearchParams(url.hash.replace(/^#/, ''));
    const action = url.searchParams.get('auth_action') || hashParams.get('type') || url.searchParams.get('type');
    const hasAuthError = url.searchParams.has('error') || hashParams.has('error');
    const credentialParameters = ['token', 'token_hash', 'access_token', 'refresh_token', 'code'];
    const hasQueryCredential = credentialParameters.some(parameter => url.searchParams.has(parameter));

    if (hasQueryCredential) {
        showToast('This security link uses an unsupported format. Request a new email and use its secure link.', true);
        [...credentialParameters, 'type', 'error', 'error_code', 'error_description']
            .forEach(parameter => url.searchParams.delete(parameter));
        url.hash = '';
        const cleanUrl = url.pathname + (url.searchParams.size ? `?${url.searchParams.toString()}` : '');
        window.history.replaceState(window.history.state, document.title, cleanUrl);
        return;
    }

    if (action === 'recovery') {
        // Supabase can return either the template token hash or an already
        // verified access token. Only fragment credentials are forwarded.
        const tokenHash = hashParams.get('token_hash');
        const accessToken = hashParams.get('access_token');
        if (!hasAuthError && (tokenHash || accessToken)) {
            const recoveryUrl = new URL('/reset-password', 'https://mathmetaverse.space');
            const recoveryParams = new URLSearchParams({ type: 'recovery' });
            if (tokenHash) recoveryParams.set('token_hash', tokenHash);
            if (!tokenHash && accessToken) recoveryParams.set('access_token', accessToken);
            recoveryUrl.hash = recoveryParams.toString();
            window.location.replace(recoveryUrl.toString());
            return;
        }

        showToast(
            hasAuthError
                ? 'This password reset link is invalid or expired.'
                : 'This reset link is incomplete. Request a new password reset email.',
            true
        );
        ['token', 'token_hash', 'access_token', 'refresh_token', 'type', 'error', 'error_code', 'error_description']
            .forEach(parameter => url.searchParams.delete(parameter));
        url.hash = '';
        window.history.replaceState(window.history.state, document.title, url.pathname);
        return;
    }

    const messages = {
        signup: 'Email confirmed successfully. You can now sign in.',
        email_change: 'Email address changed successfully.',
        email_change_current: 'Email address changed successfully.',
        email_change_new: 'Email address changed successfully.',
    };
    const isConfirmationReturn = Object.prototype.hasOwnProperty.call(messages, action);

    if (!isConfirmationReturn) return;

    showToast(
        hasAuthError ? 'This email confirmation link is invalid or expired.' : messages[action],
        hasAuthError
    );

    ['auth_action', 'code', 'token', 'token_hash', 'access_token', 'refresh_token', 'type', 'error', 'error_code', 'error_description']
        .forEach(parameter => url.searchParams.delete(parameter));
    url.hash = '';
    const cleanUrl = url.pathname + (url.searchParams.size ? `?${url.searchParams.toString()}` : '');
    window.history.replaceState(window.history.state, document.title, cleanUrl);
}

const scheduleAuthConfirmationReturn = () => window.setTimeout(handleAuthConfirmationReturn, 0);
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleAuthConfirmationReturn, { once: true });
} else {
    scheduleAuthConfirmationReturn();
}

function tglPass(id, icoId) {
    const inp = document.getElementById(id);
    const ico = document.getElementById(icoId);
    if (!inp || !ico) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        ico.classList.replace('fa-eye-slash', 'fa-eye');
        ico.classList.add('text-cyan-400');
    } else {
        inp.type = 'password';
        ico.classList.replace('fa-eye', 'fa-eye-slash');
        ico.classList.remove('text-cyan-400');
    }
}

async function copyToClipboard(text) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const helper = document.createElement('textarea');
            helper.value = text;
            helper.setAttribute('readonly', '');
            helper.style.position = 'fixed';
            helper.style.opacity = '0';
            document.body.appendChild(helper);
            helper.select();
            if (!document.execCommand('copy')) throw new Error('Copy failed');
            helper.remove();
        }
        showToast('Code copied: ' + text);
    } catch (error) {
        showToast('The code could not be copied. Select and copy it manually.', true);
    }
}

function openModal(id) {
    const m = document.getElementById(id);
    if (!m) return;

    m.dataset.previousFocusId = ensureElementId(document.activeElement, 'modal-trigger');
    m.setAttribute('role', m.getAttribute('role') || 'dialog');
    m.setAttribute('aria-modal', 'true');
    m.setAttribute('aria-hidden', 'false');
    ensureModalLabel(m, id);
    m.classList.remove('hidden');
    const f = m.querySelector('.portal-frame');
    if (f && !reducedMotionQuery.matches) {
        f.classList.remove('animate-fade-in');
        void f.offsetWidth;
        f.classList.add('animate-fade-in');
    }
    document.body.classList.add('modal-open');
    requestAnimationFrame(() => {
        const focusTarget = modalFocusableElements(m)[0] ?? f ?? m;
        if (!focusTarget.hasAttribute('tabindex') && !focusTarget.matches('button, a, input, select, textarea')) {
            focusTarget.setAttribute('tabindex', '-1');
        }
        focusTarget.focus({ preventScroll: true });
    });
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    modal.querySelector('.portal-frame')?.classList.remove('animate-fade-in');

    if (!document.querySelector('.modal-overlay:not(.hidden)')) {
        document.body.classList.remove('modal-open');
    }

    const previousFocus = modal.dataset.previousFocusId
        ? document.getElementById(modal.dataset.previousFocusId)
        : null;
    previousFocus?.focus({ preventScroll: true });
}

function modalFocusableElements(modal) {
    return [...modal.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )].filter(element => !element.closest('.hidden') && element.getClientRects().length > 0);
}

function ensureElementId(element, prefix) {
    if (!(element instanceof HTMLElement) || element === document.body) return '';
    if (!element.id) element.id = `${prefix}-${globalThis.crypto?.randomUUID?.() ?? Date.now()}`;
    return element.id;
}

function ensureModalLabel(modal, id) {
    if (modal.hasAttribute('aria-label') || modal.hasAttribute('aria-labelledby')) return;
    const title = modal.querySelector('h1, h2, h3');
    if (!title) {
        modal.setAttribute('aria-label', 'Dialog');
        return;
    }
    if (!title.id) title.id = `${id}-title`;
    modal.setAttribute('aria-labelledby', title.id);
}

document.addEventListener('keydown', event => {
    const modals = [...document.querySelectorAll('.modal-overlay:not(.hidden)')];
    const modal = modals[modals.length - 1];
    if (!modal) return;

    if (event.key === 'Escape') {
        event.preventDefault();
        closeModal(modal.id);
        return;
    }
    if (event.key !== 'Tab') return;

    const focusable = modalFocusableElements(modal);
    if (!focusable.length) {
        event.preventDefault();
        modal.focus();
        return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
});

function toggleSidebar(forceOpen = null) {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    if (!sidebar || !overlay) return;

    const currentlyOpen = !sidebar.classList.contains('-translate-x-full');
    const shouldOpen = forceOpen === null ? !currentlyOpen : Boolean(forceOpen);
    sidebar.classList.toggle('-translate-x-full', !shouldOpen);
    overlay.classList.toggle('hidden', !shouldOpen);
    document.body.classList.toggle('sidebar-open', shouldOpen && window.innerWidth < 768);
    document.querySelectorAll('[data-sidebar-toggle]').forEach(button => {
        button.setAttribute('aria-expanded', String(shouldOpen));
        button.setAttribute('aria-label', shouldOpen ? 'Close navigation' : 'Open navigation');
    });
}

function showSection(id) {
    const sec = document.getElementById('sec-' + id);
    if (!sec) return false;

    document.querySelectorAll('.content-section').forEach(s => {
        s.classList.add('hidden');
        s.classList.remove('animate-fade-in');
    });
    document.querySelectorAll('.nav-link').forEach(b => b.classList.remove('active'));
    sec.classList.remove('hidden');
    void sec.offsetWidth;
    sec.classList.add('animate-fade-in');
    document.getElementById('btn-' + id)?.classList.add('active');

    if (window.innerWidth < 768) toggleSidebar(false);

    return true;
}

window.addEventListener('resize', () => {
    if (window.innerWidth >= 768) {
        document.getElementById('sidebar-overlay')?.classList.add('hidden');
        document.body.classList.remove('sidebar-open');
        document.querySelectorAll('[data-sidebar-toggle]').forEach(button => {
            button.setAttribute('aria-expanded', 'false');
        });
    }
});

// Read CSRF token from meta tag (set in layout)
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}
onMathVerseReady(() => {
    const sections = [...document.querySelectorAll('.content-section')];
    if (!sections.length) return;

    const requested = new URLSearchParams(window.location.search).get('section');
    const section = requested === 'password' ? 'security' : requested;
    const visible = sections.find(item => !item.classList.contains('hidden'));
    const fallback = visible?.id.replace(/^sec-/, '') ?? 'stats';
    showSection(section || fallback);
});

function syncTemporalInputTone(input) {
    input?.classList.toggle('temporal-input-empty', !input.value);
}

onMathVerseReady(() => {
    document.querySelectorAll('input[type="date"], input[type="datetime-local"], input[type="month"], input[type="time"]')
        .forEach(input => {
            if (input.dataset.temporalToneReady === 'true') return;
            input.dataset.temporalToneReady = 'true';
            syncTemporalInputTone(input);
            input.addEventListener('input', () => syncTemporalInputTone(input));
            input.addEventListener('change', () => syncTemporalInputTone(input));
        });
});

const MAX_AVATAR_SIZE_BYTES = 2 * 1024 * 1024;
const AVATAR_SIZE_ERROR = 'The selected image must be 2 MB or less.';
const AVATAR_TYPE_ERROR = 'Choose a JPEG, PNG, or WebP image.';
const ALLOWED_AVATAR_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
let invalidAvatarInput = null;
const avatarPreviewStates = new WeakMap();

function avatarPreviewState(input) {
    if (avatarPreviewStates.has(input)) return avatarPreviewStates.get(input);
    const form = input.closest('form');
    const preview = form?.querySelector('[data-avatar-preview]');
    if (!preview) return null;
    const placeholder = form.querySelector('[data-avatar-placeholder]');
    const state = {
        preview, placeholder,
        src: preview.getAttribute('src'),
        hidden: preview.hidden || preview.classList.contains('hidden'),
        placeholderHidden: placeholder?.hidden || placeholder?.classList.contains('hidden') || false,
    };
    avatarPreviewStates.set(input, state);
    return state;
}

function resetAvatarPreview(input) {
    const state = avatarPreviewState(input);
    if (!state) return;
    if (state.src) state.preview.src = state.src;
    else state.preview.removeAttribute('src');
    state.preview.hidden = state.hidden;
    state.preview.classList.toggle('hidden', state.hidden);
    if (state.placeholder) {
        state.placeholder.hidden = state.placeholderHidden;
        state.placeholder.classList.toggle('hidden', state.placeholderHidden);
    }
}

function avatarValidationError(file) {
    if (!file) return '';
    if (file.size < 1 || file.size > MAX_AVATAR_SIZE_BYTES) return AVATAR_SIZE_ERROR;
    if (!ALLOWED_AVATAR_TYPES.has(file.type.toLowerCase())) return AVATAR_TYPE_ERROR;
    return '';
}

function showAvatarValidationModal(input, error) {
    const file = input.files?.[0];
    if (!file) return;

    invalidAvatarInput = input;
    const message = document.getElementById('image-size-message');
    const fileDetails = document.getElementById('image-size-file');

    if (message) message.textContent = error;
    if (fileDetails) {
        const sizeInMb = (file.size / (1024 * 1024)).toFixed(2);
        fileDetails.textContent = `${file.name} (${sizeInMb} MB)`;
    }

    openModal('imageSizeModal');
}

function validateAvatarSize(input) {
    const file = input.files?.[0];
    const error = avatarValidationError(file);

    if (!error) {
        input.removeAttribute('aria-invalid');
        input.setCustomValidity('');
        if (invalidAvatarInput === input) {
            invalidAvatarInput = null;
        }
        return true;
    }

    input.setAttribute('aria-invalid', 'true');
    input.setCustomValidity(error);
    showAvatarValidationModal(input, error);
    return false;
}

function chooseAnotherAvatar() {
    const input = invalidAvatarInput
        ?? document.querySelector('input[type="file"][name="avatar"]');

    closeModal('imageSizeModal');
    if (input) {
        input.value = '';
        validateAvatarSize(input);
        resetAvatarPreview(input);
    }
    setTimeout(() => input?.click(), 100);
}

document.addEventListener('mathverse:before-navigate', () => {
    invalidAvatarInput = null;
    closeModal('imageSizeModal');
});

onMathVerseReady(() => {
    document.querySelectorAll('input[type="file"][name="avatar"]').forEach(input => {
        if (input.dataset.avatarValidationReady === 'true') return;
        input.dataset.avatarValidationReady = 'true';
        const form = input.closest('form');
        if (!form) return;

        avatarPreviewState(input);
        input.addEventListener('change', () => previewAvatar(input));
        form.addEventListener('reset', () => queueMicrotask(() => {
            validateAvatarSize(input);
            resetAvatarPreview(input);
        }));

        form.addEventListener('submit', event => {
            if (!validateAvatarSize(input)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });
    });
});

function previewAvatar(input) {
    const file = input.files?.[0];
    const state = avatarPreviewState(input);
    if (!state) return;
    if (!validateAvatarSize(input) || !file) {
        resetAvatarPreview(input);
        return;
    }

    const reader = new FileReader();
    reader.onload = e => {
        // A late read from a previous selection/navigation must not replace
        // the new draft. Never change the saved header/menu avatar here.
        if (!input.isConnected || input.files?.[0] !== file) return;
        state.preview.src = e.target.result;
        state.preview.hidden = false;
        state.preview.classList.remove('hidden');
        if (state.placeholder) {
            state.placeholder.hidden = true;
            state.placeholder.classList.add('hidden');
        }
    };
    reader.readAsDataURL(file);
}
