const RELEASE_FORM_SELECTOR = '#app-release-upload-form';
export const DEFAULT_PART_SIZE = 16 * 1024 * 1024;
export const MAX_PARALLEL_PARTS = 3;
export const PART_RETRY_DELAYS = [0, 1000, 3000, 7000, 15000];
export const PART_REQUEST_TIMEOUT = 10 * 60 * 1000;

let activeUpload = null;

function csrfToken() {
    if (typeof document === 'undefined') return '';
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function notify(message, isError = false) {
    if (typeof window !== 'undefined' && typeof window.showToast === 'function') window.showToast(message, isError);
}

function foregroundFetch(url, init) {
    const request = window.mathVerseForegroundFetch || window.fetch.bind(window);
    return request(url, init, { scope: 'mutation', timeoutMs: 30000 });
}

async function jsonResponse(response) {
    let payload = {};
    try { payload = await response.json(); } catch { payload = {}; }

    if (!response.ok) {
        const validationMessage = Object.values(payload.errors || {}).flat().find(message => typeof message === 'string');
        const error = new Error(validationMessage || payload.message || 'The request could not be completed.');
        error.status = response.status;
        throw error;
    }
    return payload;
}

function requestOptions(payload) {
    return {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    };
}

function setUploadUi(form, { visible = true, percent = 0, status = 'Preparing upload…', disabled = true } = {}) {
    const progress = form.querySelector('#app-release-upload-progress');
    const bar = form.querySelector('#app-release-upload-bar');
    const percentage = form.querySelector('#app-release-upload-percent');
    const statusText = form.querySelector('#app-release-upload-status');
    const submit = form.querySelector('[data-upload-submit]');
    const safePercent = Math.max(0, Math.min(100, Math.round(percent)));

    progress?.classList.toggle('hidden', !visible);
    if (bar) bar.style.width = `${safePercent}%`;
    if (percentage) percentage.textContent = `${safePercent}%`;
    if (statusText) statusText.textContent = status;
    if (submit) submit.disabled = disabled;
}

async function recordFailure(url, reason) {
    if (!url) return;
    try {
        await fetch(url, requestOptions({ reason: String(reason || 'Upload interrupted.').slice(0, 500) }));
    } catch {
        // The server retains the unfinished row if this best-effort cleanup fails.
    }
}

function replaceReleaseId(template, releaseId) {
    return String(template || '').replace('__RELEASE_ID__', encodeURIComponent(releaseId));
}

function wait(milliseconds) {
    return new Promise(resolve => setTimeout(resolve, milliseconds));
}

function responseStatus(error) {
    const status = Number(error?.status || error?.statusCode || error?.response?.status);
    return Number.isInteger(status) && status > 0 ? status : null;
}

export function isRetryablePartError(error) {
    const status = responseStatus(error);
    return status === null || status === 408 || status === 429 || status >= 500;
}

export function uploadFailureMessage(error) {
    const status = responseStatus(error);
    if (status === 401 || status === 403) return `The secure R2 upload authorization was rejected (HTTP ${status}). Start the upload again.`;
    if (status === 409) return 'A build already exists for this version (HTTP 409). Use a new version number.';
    if (status === 413) return 'This build is larger than the configured game upload limit (HTTP 413).';
    if (status === 415) return 'The storage service rejected this file type (HTTP 415). Upload a valid APK or IPA.';
    if (status === 429) return 'The upload service is receiving too many requests (HTTP 429). Wait a moment, then retry.';
    if (status !== null) return `Cloudflare R2 could not accept the build (HTTP ${status}). Please retry.`;
    return 'The upload was interrupted before Cloudflare R2 returned a response. Check the connection and retry.';
}

export function isSupportedMobileBuild(fileName) {
    return /\.(?:apk|ipa)$/i.test(String(fileName || ''));
}

export function putR2Part(url, blob, { onProgress = () => {}, signal, XMLHttpRequestConstructor } = {}) {
    const Request = XMLHttpRequestConstructor || globalThis.XMLHttpRequest;
    return new Promise((resolve, reject) => {
        if (typeof Request !== 'function') {
            reject(new Error('This browser cannot upload files.'));
            return;
        }

        const request = new Request();
        const abort = () => request.abort();
        request.open('PUT', url, true);
        request.timeout = PART_REQUEST_TIMEOUT;
        request.upload.addEventListener('progress', event => {
            if (event.lengthComputable) onProgress(event.loaded, event.total);
        });
        request.addEventListener('load', () => {
            signal?.removeEventListener('abort', abort);
            if (request.status >= 200 && request.status < 300) {
                onProgress(blob.size, blob.size);
                resolve(request.getResponseHeader('ETag'));
                return;
            }
            const error = new Error(`R2 part upload failed with HTTP ${request.status}.`);
            error.status = request.status;
            reject(error);
        });
        request.addEventListener('error', () => {
            signal?.removeEventListener('abort', abort);
            reject(new Error('The R2 part upload lost its connection.'));
        });
        request.addEventListener('timeout', () => {
            signal?.removeEventListener('abort', abort);
            reject(new Error('The R2 part upload timed out.'));
        });
        request.addEventListener('abort', () => {
            signal?.removeEventListener('abort', abort);
            const error = new Error('The upload was cancelled.');
            error.name = 'AbortError';
            reject(error);
        });

        if (signal?.aborted) {
            abort();
            return;
        }
        signal?.addEventListener('abort', abort, { once: true });
        request.send(blob);
    });
}

async function signedPartUrl(signUrl, partNumber, jsonRequest, signal) {
    const payload = await jsonResponse(await jsonRequest(
        signUrl,
        { ...requestOptions({ part_number: partNumber }), signal },
    ));
    if (!payload.url || typeof payload.url !== 'string') throw new Error('The secure upload URL was incomplete.');
    return payload.url;
}

export async function uploadR2Parts({
    file,
    signUrl,
    partSize = DEFAULT_PART_SIZE,
    concurrency = MAX_PARALLEL_PARTS,
    jsonRequest = (url, init) => fetch(url, init),
    putPart = putR2Part,
    signal,
    onProgress = () => {},
}) {
    const safePartSize = Math.max(5 * 1024 * 1024, Number(partSize) || DEFAULT_PART_SIZE);
    const partCount = Math.ceil(file.size / safePartSize);
    const progress = new Array(partCount).fill(0);
    let nextIndex = 0;

    const report = (index, loaded) => {
        const expectedPartBytes = Math.min(safePartSize, file.size - index * safePartSize);
        progress[index] = Math.max(progress[index], Math.min(loaded, expectedPartBytes));
        onProgress(progress.reduce((total, value) => total + value, 0), file.size);
    };

    const worker = async () => {
        while (nextIndex < partCount) {
            if (signal?.aborted) throw Object.assign(new Error('The upload was cancelled.'), { name: 'AbortError' });
            const index = nextIndex++;
            const partNumber = index + 1;
            const start = index * safePartSize;
            const end = Math.min(file.size, start + safePartSize);
            const blob = file.slice(start, end);
            let lastError;

            for (const delay of PART_RETRY_DELAYS) {
                if (delay > 0) await wait(delay);
                if (signal?.aborted) throw Object.assign(new Error('The upload was cancelled.'), { name: 'AbortError' });
                try {
                    const url = await signedPartUrl(signUrl, partNumber, jsonRequest, signal);
                    await putPart(url, blob, { signal, onProgress: loaded => report(index, loaded) });
                    report(index, blob.size);
                    lastError = null;
                    break;
                } catch (error) {
                    lastError = error;
                    progress[index] = 0;
                    if (error?.name === 'AbortError') throw error;
                    if (!isRetryablePartError(error)) break;
                }
            }
            if (lastError) throw lastError;
        }
    };

    await Promise.all(Array.from(
        { length: Math.min(Math.max(1, concurrency), partCount) },
        () => worker(),
    ));
}

export async function beginUpload(form, dependencies = {}) {
    const foregroundRequest = dependencies.foregroundRequest || foregroundFetch;
    const jsonRequest = dependencies.jsonRequest || ((url, init) => fetch(url, init));
    const failureRecorder = dependencies.failureRecorder || recordFailure;
    const notifyUser = dependencies.notifyUser || notify;
    const partUploader = dependencies.partUploader || uploadR2Parts;
    const navigation = dependencies.navigation || (typeof window !== 'undefined' ? window.MathVerseNavigation : null);
    const fileInput = form.elements.namedItem('release_file');
    const versionInput = form.elements.namedItem('version');
    const notesInput = form.elements.namedItem('release_notes');
    const file = fileInput?.files?.[0];

    if (typeof File === 'undefined' || !(file instanceof File) || !versionInput?.value?.trim()) {
        form.reportValidity();
        return null;
    }
    if (!isSupportedMobileBuild(file.name)) {
        notifyUser('Choose an Android APK or an iOS IPA file.', true);
        return null;
    }

    setUploadUi(form, { status: 'Preparing secure R2 upload…' });
    let prepared;
    try {
        prepared = await jsonResponse(await foregroundRequest(form.dataset.initiateUrl, requestOptions({
            version: versionInput.value.trim(),
            file_name: file.name,
            file_size: file.size,
            release_notes: notesInput?.value?.trim() || null,
        })));
    } catch (error) {
        setUploadUi(form, { visible: false, disabled: false });
        notifyUser(error.message || 'The upload could not be started.', true);
        return null;
    }

    const releaseId = prepared.release?.id;
    const uploadDetails = prepared.upload || {};
    const completeUrl = replaceReleaseId(form.dataset.completeUrlTemplate, releaseId);
    const failUrl = replaceReleaseId(form.dataset.failUrlTemplate, releaseId);
    if (!releaseId || !uploadDetails.sign_part_url || !Number.isInteger(Number(uploadDetails.part_size))) {
        setUploadUi(form, { visible: false, disabled: false });
        notifyUser('The secure upload details were incomplete. Please try again.', true);
        await failureRecorder(failUrl, 'The R2 multipart upload details were incomplete.');
        return null;
    }

    const abortController = new AbortController();
    activeUpload = { abort: () => abortController.abort() };
    try {
        await partUploader({
            file,
            signUrl: uploadDetails.sign_part_url,
            partSize: Number(uploadDetails.part_size),
            jsonRequest,
            signal: abortController.signal,
            onProgress(bytesUploaded, bytesTotal) {
                if (!form.isConnected) return;
                const percent = bytesTotal > 0 ? (bytesUploaded / bytesTotal) * 100 : 0;
                setUploadUi(form, { percent, status: 'Uploading mobile build to Cloudflare R2…' });
            },
        });
    } catch (error) {
        abortController.abort();
        activeUpload = null;
        const failureMessage = uploadFailureMessage(error);
        if (form.isConnected) setUploadUi(form, { status: failureMessage, disabled: false });
        await failureRecorder(failUrl, failureMessage);
        notifyUser(failureMessage, true);
        return null;
    }

    activeUpload = null;
    if (form.isConnected) setUploadUi(form, { percent: 100, status: 'Verifying and publishing…' });
    try {
        const completed = await jsonResponse(await foregroundRequest(completeUrl, requestOptions({})));
        notifyUser(completed.message || 'The new game version is now available.');
        if (form.isConnected && navigation?.refresh) await navigation.refresh();
        else navigation?.clearCache?.();
    } catch (error) {
        if (form.isConnected) {
            setUploadUi(form, {
                percent: 100,
                status: 'Upload saved; publishing needs attention.',
                disabled: false,
            });
        }
        notifyUser(error.message || 'The upload finished but could not be published.', true);
        navigation?.clearCache?.();
        if (form.isConnected && navigation?.refresh) {
            try { await navigation.refresh(); } catch {
                // The unfinished release remains available after a normal reload.
            }
        }
        return null;
    }
    return true;
}

if (typeof document !== 'undefined') {
    document.addEventListener('submit', event => {
        const form = event.target.closest?.(RELEASE_FORM_SELECTOR);
        if (!form) return;
        event.preventDefault();
        if (activeUpload) {
            notify('A mobile build is already uploading.', true);
            return;
        }
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        void beginUpload(form);
    });
}

if (typeof window !== 'undefined') {
    window.addEventListener('beforeunload', event => {
        if (!activeUpload) return;
        event.preventDefault();
        event.returnValue = '';
    });
}
