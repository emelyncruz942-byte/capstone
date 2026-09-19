(() => {
    const contentSelector = '#dashboard-content';
    const modalsSelector = '#dashboard-modals';
    const maxCachedPages = 10;
    const freshForMs = 20000;
    const visibleRefreshMs = 300000;
    const pageRequestTimeoutMs = 20000;
    const formRequestTimeoutMs = 30000;
    // Public auth responses must navigate normally so redirects, validation
    // feedback, and one-time token/session changes are not consumed by AJAX.
    const nativeAuthPaths = new Set(['/login', '/register', '/forgot-password', '/update-password', '/auth/confirm', '/logout']);
    const pageCache = new Map();
    const inFlight = new Map();
    const backgroundRefreshes = new Map();
    let cacheGeneration = 0;
    let navigationSequence = 0;
    let renderedUrl = window.location.href;
    let lastVisibleRefreshAt = Date.now();
    let visibleRefreshTimer = null;
    let activePageNavigation = null;

    class NativeNavigationRequired extends Error {
        constructor(url) {
            super('This response requires a full navigation.');
            this.url = url;
        }
    }

    class RequestTimeout extends Error {
        constructor(mutating) {
            super(mutating
                ? 'This action is taking too long. It may already have completed; check the current records before submitting it again.'
                : 'This page took too long to load. Try again or open another page.');
        }
    }

    function cancelPageNavigation() {
        activePageNavigation?.controller.abort();
        activePageNavigation = null;
    }

    // Cover the response body as well as the initial fetch. No timed-out
    // mutation is retried automatically: its server-side outcome may be unknown.
    async function requestDocument(url, options = {}, timeoutMs = pageRequestTimeoutMs) {
        const controller = new AbortController();
        const upstreamSignal = options.signal;
        const forwardAbort = () => controller.abort(upstreamSignal.reason);
        upstreamSignal?.addEventListener('abort', forwardAbort, { once: true });
        if (upstreamSignal?.aborted) forwardAbort();

        let rejectAborted;
        const aborted = new Promise((resolve, reject) => {
            rejectAborted = () => reject(controller.signal.reason || new DOMException('Request aborted.', 'AbortError'));
            controller.signal.addEventListener('abort', rejectAborted, { once: true });
            if (controller.signal.aborted) rejectAborted();
        });
        const timeout = window.setTimeout(() => {
            controller.abort(new RequestTimeout(String(options.method || 'GET').toUpperCase() !== 'GET'));
        }, timeoutMs);

        try {
            return await Promise.race([
                (async () => {
                    const response = await fetch(url, { ...options, signal: controller.signal });
                    const responseType = response.headers.get('Content-Type') || '';
                    const html = responseType.includes('text/html') ? await response.text() : '';
                    const payload = responseType.includes('application/json')
                        ? await response.json().catch(() => ({})) : {};
                    return { response, responseType, html, payload };
                })(),
                aborted,
            ]);
        } finally {
            window.clearTimeout(timeout);
            upstreamSignal?.removeEventListener('abort', forwardAbort);
            controller.signal.removeEventListener('abort', rejectAborted);
        }
    }

    function currentRole() {
        return document.querySelector(contentSelector)?.dataset.dashboardRole || '';
    }

    function toUrl(value) {
        try {
            return new URL(value, window.location.href);
        } catch {
            return null;
        }
    }

    function cacheKey(url) {
        return `${url.pathname}${url.search}`;
    }

    function clearPageCache() {
        cacheGeneration++;
        pageCache.clear();
        inFlight.clear();
    }

    function isDownloadUrl(url) {
        const format = url.searchParams.get('format');
        return url.pathname.includes('/report/')
            || ['pdf', 'csv', 'xlsx'].includes(String(format).toLowerCase())
            || /\.(?:pdf|csv|xlsx|zip)$/i.test(url.pathname);
    }

    function isDashboardUrl(url) {
        const role = currentRole();
        return Boolean(role)
            && url.origin === window.location.origin
            && url.pathname.startsWith(`/${role}/`)
            && !isDownloadUrl(url);
    }

    function eligibleLink(anchor) {
        if (!anchor
            || anchor.hasAttribute('download')
            || anchor.hasAttribute('data-native-navigation')
            || (anchor.target && anchor.target !== '_self')
            || anchor.getAttribute('rel')?.split(/\s+/).includes('external')) {
            return null;
        }

        const rawHref = anchor.getAttribute('href');
        if (!rawHref || rawHref === '#' || rawHref.startsWith('mailto:') || rawHref.startsWith('tel:')) return null;
        const url = toUrl(anchor.href);
        return url && isDashboardUrl(url) ? url : null;
    }

    function setLoading(loading) {
        document.body.classList.toggle('mathverse-navigating', loading);
        document.querySelector(contentSelector)?.setAttribute('aria-busy', String(loading));
    }

    function parsePage(html, finalUrl) {
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const content = parsed.querySelector(contentSelector);
        const modals = parsed.querySelector(modalsSelector);
        if (!content || !modals || content.dataset.dashboardRole !== currentRole()) {
            throw new NativeNavigationRequired(finalUrl);
        }
        return parsed;
    }

    function remember(entry) {
        pageCache.delete(entry.key);
        pageCache.set(entry.key, entry);
        while (pageCache.size > maxCachedPages) {
            pageCache.delete(pageCache.keys().next().value);
        }
        return entry;
    }

    async function requestPage(destination, { force = false, refreshChrome = false, signal } = {}) {
        const url = toUrl(destination);
        if (!url || !isDashboardUrl(url)) {
            throw new NativeNavigationRequired(url?.href || String(destination));
        }
        url.hash = '';
        const key = cacheKey(url);
        const requestedGeneration = cacheGeneration;

        if (!force && pageCache.has(key)) return pageCache.get(key);
        const existingRequest = inFlight.get(key);
        if (!force && existingRequest && !existingRequest.signal?.aborted) return existingRequest.promise;

        const request = (async () => {
            const { response, responseType, html, payload } = await requestDocument(url.href, {
                signal,
                credentials: 'same-origin',
                redirect: 'follow',
                cache: 'no-store',
                headers: {
                    'Accept': 'text/html',
                    'X-MathVerse-Navigation': '1',
                    'X-MathVerse-Chrome': refreshChrome ? '1' : '0',
                    'X-MathVerse-Revalidate': refreshChrome ? '1' : '0',
                },
            });
            if (!response.ok || !responseType.includes('text/html')) {
                const reference = response.headers.get('X-MathVerse-Reference');
                const error = new Error(payload.message || 'That page could not be loaded. Please try again.');
                if (reference && /^MV-[A-F0-9]{16}$/.test(reference) && !error.message.includes(reference)) error.message += ` Reference: ${reference}`;
                error.hasReference = Boolean(reference && /^MV-[A-F0-9]{16}$/.test(reference));
                throw error;
            }

            const finalUrl = response.url || url.href;
            parsePage(html, finalUrl);
            const entry = {
                key: cacheKey(new URL(finalUrl)),
                html,
                finalUrl,
                storedAt: Date.now(),
                cacheGeneration: requestedGeneration,
                feedbackConsumed: false,
            };
            return requestedGeneration === cacheGeneration ? remember(entry) : entry;
        })();

        if (!force) inFlight.set(key, { promise: request, signal });
        try {
            return await request;
        } finally {
            if (!force && inFlight.get(key)?.promise === request) inFlight.delete(key);
        }
    }

    function saveScrollPosition() {
        const state = { ...(window.history.state || {}), mathverse: true, scrollY: window.scrollY };
        window.history.replaceState(state, document.title, window.location.href);
    }

    function updateHistory(url, mode, scrollY = 0, extraState = {}) {
        if (mode === 'none') return;
        const state = { mathverse: true, scrollY, ...extraState };
        if (mode === 'replace') {
            window.history.replaceState(state, document.title, url);
        } else {
            window.history.pushState(state, document.title, url);
        }
    }

    function updateSidebar(parsed) {
        const incomingLinks = [...parsed.querySelectorAll('#sidebar .nav-link')];
        const activeUrls = new Set(
            incomingLinks
                .filter(link => link.classList.contains('active'))
                .map(link => cacheKey(new URL(link.href, window.location.origin)))
        );

        document.querySelectorAll('#sidebar .nav-link').forEach(link => {
            const url = new URL(link.href, window.location.origin);
            const active = activeUrls.has(cacheKey(url));
            link.classList.toggle('active', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
    }

    function replaceMatchingRoots(parsed, selector) {
        const current = [...document.querySelectorAll(selector)];
        const incoming = [...parsed.querySelectorAll(selector)];
        if (!current.length || current.length !== incoming.length) return;
        current.forEach((root, index) => root.replaceWith(incoming[index].cloneNode(true)));
    }

    function syncPersistentChrome(parsed) {
        replaceMatchingRoots(parsed, '[data-notification-root]');
        replaceMatchingRoots(parsed, '[data-profile-root]');

        const currentNav = document.querySelector('#sidebar nav');
        const incomingNav = parsed.querySelector('#sidebar nav');
        if (currentNav && incomingNav) currentNav.replaceWith(incomingNav.cloneNode(true));
        window.MathVerseNotifications?.markFresh();
    }

    function safeToRevalidateVisiblePage() {
        const focused = document.activeElement;
        return !document.body.classList.contains('mathverse-navigating')
            && !document.querySelector('form[data-mathverse-submitting="true"]')
            && !document.querySelector(`${contentSelector} form[data-mathverse-dirty="true"]`)
            && !document.querySelector(`${contentSelector} [data-seamless-refresh="manual"]`)
            && !document.querySelector('.modal-overlay:not(.hidden)')
            && !(focused instanceof HTMLElement && focused.matches('input, textarea, select, [contenteditable="true"]'));
    }

    function focusPageHeading() {
        const heading = document.querySelector(`${contentSelector} h1, ${contentSelector} h2`);
        if (!(heading instanceof HTMLElement)) return;
        heading.setAttribute('tabindex', '-1');
        heading.focus({ preventScroll: true });
        heading.addEventListener('blur', () => heading.removeAttribute('tabindex'), { once: true });
    }

    function focusDestination(hash, fallbackToHeading = true) {
        let target = null;
        if (hash) {
            try {
                target = document.getElementById(decodeURIComponent(hash.slice(1)));
            } catch {
                target = document.getElementById(hash.slice(1));
            }
        }

        if (!(target instanceof HTMLElement)) {
            if (fallbackToHeading) focusPageHeading();
            return;
        }

        const temporaryTabIndex = !target.hasAttribute('tabindex');
        if (temporaryTabIndex) target.setAttribute('tabindex', '-1');
        target.focus({ preventScroll: true });
        target.scrollIntoView({ block: 'start' });
        if (temporaryTabIndex) {
            target.addEventListener('blur', () => target.removeAttribute('tabindex'), { once: true });
        }
    }

    function applyPage(entry, {
        historyMode = 'push',
        focusHeading = true,
        preserveScroll = false,
        syncChrome = true,
        destinationHash = '',
    } = {}) {
        const parsed = parsePage(entry.html, entry.finalUrl);
        const incomingContent = parsed.querySelector(contentSelector);
        const incomingModals = parsed.querySelector(modalsSelector);
        const previousScroll = window.scrollY;

        document.dispatchEvent(new CustomEvent('mathverse:before-navigate'));
        document.querySelector(contentSelector).replaceWith(incomingContent);
        document.querySelector(modalsSelector).replaceWith(incomingModals);
        document.body.classList.remove('modal-open', 'sidebar-open');
        toggleSidebar(false);

        const incomingTitle = parsed.querySelector('#dashboard-mobile-title')?.textContent?.trim();
        if (incomingTitle) document.getElementById('dashboard-mobile-title').textContent = incomingTitle;
        document.title = parsed.title || document.title;

        const incomingCsrf = parsed.querySelector('meta[name="csrf-token"]')?.content;
        if (incomingCsrf) document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', incomingCsrf);

        if (syncChrome && incomingContent.dataset.dashboardChromeFresh === 'true') {
            syncPersistentChrome(parsed);
        } else {
            updateSidebar(parsed);
        }

        const displayedUrl = new URL(entry.finalUrl, window.location.origin);
        if (destinationHash) displayedUrl.hash = destinationHash;
        updateHistory(displayedUrl.href, historyMode, preserveScroll ? previousScroll : 0);
        lastVisibleRefreshAt = Date.now();
        if (!entry.feedbackConsumed) {
            displayMathVerseDocumentFeedback(parsed);
            entry.feedbackConsumed = true;
            if (displayedUrl.searchParams.has('notice')) {
                displayedUrl.searchParams.delete('notice');
                entry.finalUrl = displayedUrl.href;
            }
        }
        renderedUrl = displayedUrl.href;
        document.dispatchEvent(new CustomEvent('mathverse:page-ready', {
            detail: { url: displayedUrl.href },
        }));

        if (preserveScroll) {
            window.scrollTo({ top: previousScroll, left: 0, behavior: 'auto' });
        } else {
            window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
            if (destinationHash || focusHeading) {
                requestAnimationFrame(() => focusDestination(destinationHash, focusHeading));
            }
        }
    }

    function localDashboardSection(url, historyMode = 'push') {
        const rendered = toUrl(renderedUrl);
        if (!rendered || url.pathname !== rendered.pathname) return false;
        if ([...url.searchParams.keys()].some(key => key !== 'section')) return false;
        if ([...rendered.searchParams.keys()].some(key => key !== 'section')) return false;
        const requested = url.searchParams.get('section');
        const defaultSection = currentRole() === 'student' ? 'stats' : 'overview';
        const section = requested === 'password' ? 'security' : (requested || defaultSection);
        if (typeof showSection !== 'function' || !showSection(section)) return false;

        if (historyMode !== 'none') {
            saveScrollPosition();
            updateHistory(url.href, historyMode, window.scrollY, { mathverseLocalSection: true });
        }
        renderedUrl = url.href;
        document.dispatchEvent(new CustomEvent('mathverse:section-change', {
            detail: { section },
        }));
        return true;
    }

    async function refreshInBackground(url, appliedSequence) {
        const refreshUrl = toUrl(url);
        if (!refreshUrl) return;
        const key = cacheKey(refreshUrl);
        const existingRefresh = backgroundRefreshes.get(key);
        if (existingRefresh) {
            existingRefresh.appliedSequence = appliedSequence;
            return existingRefresh.promise;
        }

        const refresh = { appliedSequence, promise: null };
        refresh.promise = (async () => {
            try {
                const fresh = await requestPage(refreshUrl, { force: true, refreshChrome: true });
                if (refresh.appliedSequence !== navigationSequence
                    || fresh.cacheGeneration !== cacheGeneration
                    || cacheKey(new URL(window.location.href)) !== cacheKey(new URL(fresh.finalUrl))
                    || !safeToRevalidateVisiblePage()) return;
                applyPage(fresh, {
                    historyMode: 'none',
                    focusHeading: false,
                    preserveScroll: true,
                    syncChrome: true,
                    destinationHash: refreshUrl.hash,
                });
            } catch {
                // The already-rendered page remains usable when revalidation fails.
            }
        })().finally(() => {
            backgroundRefreshes.delete(key);
        });

        backgroundRefreshes.set(key, refresh);
        return refresh.promise;
    }

    function scheduleVisibleRefresh() {
        window.clearTimeout(visibleRefreshTimer);
        const jitter = Math.floor(Math.random() * 30000);
        visibleRefreshTimer = window.setTimeout(async () => {
            if (document.visibilityState === 'visible'
                && navigator.onLine !== false
                && Date.now() - lastVisibleRefreshAt >= visibleRefreshMs
                && safeToRevalidateVisiblePage()
            ) {
                await refreshInBackground(window.location.href, navigationSequence);
            }
            scheduleVisibleRefresh();
        }, visibleRefreshMs + jitter);
    }

    async function navigate(destination, options = {}) {
        const url = toUrl(destination);
        if (!url) return false;
        const historyMode = options.historyMode || 'push';
        if (!options.force
            && options.localSection
            && localDashboardSection(url, historyMode)) {
            cancelPageNavigation();
            navigationSequence++;
            setLoading(false);
            return true;
        }

        const sequence = ++navigationSequence;
        cancelPageNavigation();
        const controller = new AbortController();
        activePageNavigation = { sequence, controller };
        const key = cacheKey(url);
        const cached = !options.force ? pageCache.get(key) : null;
        if (historyMode !== 'none') saveScrollPosition();
        setLoading(!cached);
        toggleSidebar(false);

        try {
            const entry = cached || await requestPage(url, {
                force: Boolean(options.force),
                refreshChrome: Boolean(options.refreshChrome),
                signal: controller.signal,
            });
            if (sequence !== navigationSequence) return false;

            applyPage(entry, {
                historyMode,
                focusHeading: options.focusHeading !== false,
                preserveScroll: Boolean(options.preserveScroll),
                syncChrome: true,
                destinationHash: url.hash,
            });

            if (Date.now() - entry.storedAt > freshForMs) {
                void refreshInBackground(url, sequence);
            }
            return true;
        } catch (error) {
            if (sequence !== navigationSequence) return false;
            if (error instanceof NativeNavigationRequired) {
                window.location.assign(error.url);
                return false;
            }
            showToast(error instanceof RequestTimeout || error.hasReference ? error.message : 'That page could not be loaded. Please try again.', true);
            return false;
        } finally {
            if (sequence === navigationSequence) {
                setLoading(false);
                activePageNavigation = null;
            }
        }
    }

    function formSubmissionTarget(form, submitter) {
        // A button's formAction getter defaults to the document URL, not
        // its owning form's action. Only explicit overrides take priority.
        return {
            method: String(submitter?.hasAttribute('formmethod') ? submitter.formMethod : (form.method || 'GET')).toUpperCase(),
            action: toUrl(submitter?.hasAttribute('formaction') ? submitter.formAction : (form.action || window.location.href)),
            target: submitter?.hasAttribute('formtarget') ? submitter.formTarget : form.target,
        };
    }

    async function submitForm(form, submitter) {
        const { method, action } = formSubmissionTarget(form, submitter);
        if (!action) return false;

        let formData;
        try {
            formData = new FormData(form, submitter || undefined);
        } catch {
            formData = new FormData(form);
            if (submitter?.name) formData.append(submitter.name, submitter.value);
        }

        if (method === 'GET') {
            action.search = '';
            formData.forEach((value, name) => {
                if (typeof value === 'string') action.searchParams.append(name, value);
            });
            return navigate(action.href);
        }

        const sequence = ++navigationSequence;
        cancelPageNavigation();
        saveScrollPosition();
        setLoading(true);
        clearPageCache();

        try {
            const { response, responseType, html, payload } = await requestDocument(action.href, {
                method,
                credentials: 'same-origin',
                redirect: 'follow',
                headers: {
                    'Accept': 'text/html',
                    'X-MathVerse-Navigation': '1',
                    'X-MathVerse-Chrome': '1',
                },
                body: formData,
            }, formRequestTimeoutMs);
            if (!response.ok || !responseType.includes('text/html')) {
                const reference = response.headers.get('X-MathVerse-Reference');
                const message = payload.message || 'The action could not be completed.';
                throw new Error(reference && /^MV-[A-F0-9]{16}$/.test(reference) && !message.includes(reference) ? `${message} Reference: ${reference}` : message);
            }

            const finalUrl = response.url || action.href;
            parsePage(html, finalUrl);
            document.dispatchEvent(new CustomEvent('mathverse:data-changed'));
            const finalKey = cacheKey(new URL(finalUrl));
            const entry = remember({
                key: finalKey,
                html,
                finalUrl,
                storedAt: Date.now(),
                cacheGeneration,
                feedbackConsumed: false,
            });
            if (sequence !== navigationSequence) return false;
            applyPage(entry, { historyMode: 'push', syncChrome: true });
            return true;
        } catch (error) {
            if (sequence !== navigationSequence) return false;
            if (error instanceof NativeNavigationRequired) {
                window.location.assign(error.url);
                return false;
            }
            showToast(error.message || 'The action could not be completed.', true);
            return false;
        } finally {
            if (sequence === navigationSequence) setLoading(false);
        }
    }

    function eligibleForm(form, submitter) {
        if (!(form instanceof HTMLFormElement)
            || form.hasAttribute('data-native-navigation')) return false;
        const { action, method, target } = formSubmissionTarget(form, submitter);
        if ((target && target !== '_self') || method === 'DIALOG') return false;
        return Boolean(action
            && action.origin === window.location.origin
            && !nativeAuthPaths.has(action.pathname)
            && !isDownloadUrl(action));
    }

    document.addEventListener('click', event => {
        if (event.defaultPrevented
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey) return;
        const anchor = event.target.closest('a[href]');
        const url = eligibleLink(anchor);
        if (!url) return;
        if (url.hash && url.pathname === window.location.pathname && url.search === window.location.search) return;

        event.preventDefault();
        void navigate(url.href, {
            localSection: Boolean(anchor.closest('#sidebar, [data-profile-menu]')),
        });
    });

    document.addEventListener('submit', event => {
        if (event.defaultPrevented || !eligibleForm(event.target, event.submitter)) return;
        event.preventDefault();
        const form = event.target;
        if (form.dataset.mathverseSubmitting === 'true') return;
        form.dataset.mathverseSubmitting = 'true';
        const submitter = event.submitter;
        const submission = submitForm(form, submitter);
        if (submitter) submitter.disabled = true;

        void submission.finally(() => {
            if (form.isConnected) form.dataset.mathverseSubmitting = 'false';
            if (submitter?.isConnected) submitter.disabled = false;
        });
    });

    document.addEventListener('input', event => {
        event.target.closest(`${contentSelector} form`)?.setAttribute('data-mathverse-dirty', 'true');
    });
    document.addEventListener('change', event => {
        event.target.closest(`${contentSelector} form`)?.setAttribute('data-mathverse-dirty', 'true');
    });

    window.addEventListener('popstate', event => {
        const restoreScroll = Number(event.state?.scrollY || 0);
        void navigate(window.location.href, {
            historyMode: 'none',
            focusHeading: false,
            preserveScroll: true,
            localSection: Boolean(event.state?.mathverseLocalSection),
        }).then(navigated => {
            if (navigated) window.scrollTo({ top: restoreScroll, left: 0, behavior: 'auto' });
        });
    });

    window.MathVerseNavigation = {
        navigate,
        refresh() {
            clearPageCache();
            return navigate(window.location.href, {
                force: true,
                refreshChrome: true,
                historyMode: 'replace',
                focusHeading: false,
                preserveScroll: true,
            });
        },
        clearCache() {
            clearPageCache();
        },
    };

    if ('scrollRestoration' in window.history) window.history.scrollRestoration = 'manual';
    window.history.replaceState(
        { ...(window.history.state || {}), mathverse: true, scrollY: window.scrollY },
        document.title,
        window.location.href
    );
    document.addEventListener('mathverse:data-changed', clearPageCache);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible'
            || navigator.onLine === false
            || Date.now() - lastVisibleRefreshAt < visibleRefreshMs
            || !safeToRevalidateVisiblePage()
        ) return;

        void refreshInBackground(window.location.href, navigationSequence);
    });
    window.addEventListener('online', () => {
        if (document.visibilityState === 'visible'
            && Date.now() - lastVisibleRefreshAt >= freshForMs
            && safeToRevalidateVisiblePage()) {
            void refreshInBackground(window.location.href, navigationSequence);
        }
    });
    scheduleVisibleRefresh();
})();
