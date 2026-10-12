import { Upload } from 'tus-js-client';

const RELEASE_FORM_SELECTOR = '#app-release-upload-form';
export const TUS_CHUNK_SIZE = 6 * 1024 * 1024;
let activeUpload = null;

function csrfToken() {
    if (typeof document === 'undefined') return '';
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function notify(message, isError = false) {
    if (typeof window !== 'undefined' && typeof window.showToast === 'function') {
        window.showToast(message, isError);
    }
}

function foregroundFetch(url, init) {
    const request = window.mathVerseForegroundFetch || window.fetch.bind(window);
    return request(url, init, { scope: 'mutation', timeoutMs: 30000 });
}

async function jsonResponse(response) {
    let payload = {};
    try {
        payload = await response.json();
    } catch {
        payload = {};
    }

    if (!response.ok) {
        const validationMessage = Object.values(payload.errors || {})
            .flat()
            .find(message => typeof message === 'string');
        throw new Error(validationMessage || payload.message || 'The request could not be completed.');
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
        // The server will retain the unfinished row if this best-effort cleanup fails.
    }
}

function replaceReleaseId(template, releaseId) {
    return String(template || '').replace('__RELEASE_ID__', encodeURIComponent(releaseId));
}

function tusResponseStatus(error) {
    const response = error?.originalResponse;
    const responseStatus = typeof response?.getStatus === 'function'
        ? Number(response.getStatus())
        : Number(error?.status || error?.statusCode);

    return Number.isInteger(responseStatus) && responseStatus > 0
        ? responseStatus
        : null;
}

export function uploadFailureMessage(error) {
    const status = tusResponseStatus(error);

    if (status === 401 || status === 403) {
        return `The secure upload authorization was rejected (HTTP ${status}). Start the upload again.`;
    }
    if (status === 409) {
        return 'A build already exists for this version (HTTP 409). Use a new version number.';
    }
    if (status === 413) {
        return 'This build exceeds the Supabase Storage file-size limit (HTTP 413). Increase the project Storage limit or upload a smaller build.';
    }
    if (status === 415) {
        return 'Supabase Storage rejected this file type (HTTP 415). Upload a valid APK or IPA.';
    }
    if (status === 429) {
        return 'Supabase Storage is receiving too many uploads (HTTP 429). Wait a moment, then retry.';
    }
    if (status !== null) {
        return `Supabase Storage could not accept the build (HTTP ${status}). Please retry.`;
    }

    return 'The upload was interrupted before Supabase returned a response. Check the connection and retry.';
}

export function isSupportedMobileBuild(fileName) {
    return /\.(?:apk|ipa)$/i.test(String(fileName || ''));
}

export function createTusUploadOptions({
    file,
    uploadDetails,
    onProgress,
    onSuccess,
    onError,
}) {
    return {
        endpoint: uploadDetails.endpoint,
        retryDelays: [0, 3000, 5000, 10000, 20000],
        chunkSize: TUS_CHUNK_SIZE,
        uploadDataDuringCreation: true,
        removeFingerprintOnSuccess: true,
        // Never resume bytes into a different release record that happens to
        // use the same local file name and size.
        fingerprint: () => Promise.resolve(
            `mathverse-release:${uploadDetails.bucket}/${uploadDetails.path}`,
        ),
        headers: {
            'x-signature': uploadDetails.token,
        },
        metadata: {
            bucketName: uploadDetails.bucket,
            objectName: uploadDetails.path,
            contentType: uploadDetails.content_type || file.type || 'application/octet-stream',
            cacheControl: '0',
        },
        onProgress,
        onSuccess,
        onError,
    };
}

export async function beginUpload(form, dependencies = {}) {
    const UploadConstructor = dependencies.UploadConstructor || Upload;
    const foregroundRequest = dependencies.foregroundRequest || foregroundFetch;
    const failureRecorder = dependencies.failureRecorder || recordFailure;
    const notifyUser = dependencies.notifyUser || notify;
    const navigation = dependencies.navigation
        || (typeof window !== 'undefined' ? window.MathVerseNavigation : null);
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

    setUploadUi(form, { status: 'Preparing secure upload…' });

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

    if (!releaseId || !uploadDetails.endpoint || !uploadDetails.token || !uploadDetails.bucket || !uploadDetails.path) {
        setUploadUi(form, { visible: false, disabled: false });
        notifyUser('The secure upload details were incomplete. Please try again.', true);
        await failureRecorder(failUrl, 'The signed upload details were incomplete.');
        return null;
    }

    const upload = new UploadConstructor(file, createTusUploadOptions({
        file,
        uploadDetails,
        onProgress(bytesUploaded, bytesTotal) {
            if (!form.isConnected) return;
            const percent = bytesTotal > 0 ? (bytesUploaded / bytesTotal) * 100 : 0;
            setUploadUi(form, { percent, status: 'Uploading mobile build…' });
        },
        async onSuccess() {
            activeUpload = null;
            if (form.isConnected) {
                setUploadUi(form, { percent: 100, status: 'Verifying and publishing…' });
            }

            try {
                const completed = await jsonResponse(await foregroundRequest(completeUrl, requestOptions({})));
                notifyUser(completed.message || 'The new game version is now available.');
                if (form.isConnected && navigation?.refresh) {
                    await navigation.refresh();
                } else {
                    navigation?.clearCache?.();
                }
            } catch (error) {
                if (form.isConnected) {
                    setUploadUi(form, { percent: 100, status: 'Upload saved; publishing needs attention.', disabled: false });
                }
                notifyUser(error.message || 'The upload finished but could not be published.', true);
                navigation?.clearCache?.();
                if (form.isConnected && navigation?.refresh) {
                    try {
                        await navigation.refresh();
                    } catch {
                        // The unfinished release stays visible after a normal reload.
                    }
                }
            }
        },
        async onError(error) {
            activeUpload = null;
            const failureMessage = uploadFailureMessage(error);
            if (form.isConnected) {
                setUploadUi(form, { status: failureMessage, disabled: false });
            }
            await failureRecorder(failUrl, failureMessage);
            notifyUser(failureMessage, true);
        },
    }));

    activeUpload = upload;
    try {
        const previousUploads = await upload.findPreviousUploads();
        if (previousUploads.length > 0) {
            upload.resumeFromPreviousUpload(previousUploads[0]);
        }
        upload.start();
    } catch (error) {
        activeUpload = null;
        const failureMessage = uploadFailureMessage(error);
        setUploadUi(form, { status: failureMessage, disabled: false });
        await failureRecorder(failUrl, failureMessage);
        notifyUser(failureMessage, true);
        return null;
    }

    return upload;
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
