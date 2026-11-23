import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/app-release-upload.js', import.meta.url), 'utf8');
const adminView = readFileSync(new URL('../../resources/views/admin/app-releases/index.blade.php', import.meta.url), 'utf8');
const uploadModule = await import(new URL('../../resources/js/app-release-upload.js', import.meta.url));

test('mobile builds use private R2 multipart uploads without exposing credentials', () => {
    assert.equal(uploadModule.DEFAULT_PART_SIZE, 16 * 1024 * 1024);
    assert.equal(uploadModule.MAX_PARALLEL_PARTS, 3);
    assert.match(source, /sign_part_url/);
    assert.match(source, /request\.open\('PUT'/);
    assert.match(source, /PART_RETRY_DELAYS/);
    assert.doesNotMatch(source, /access[_-]?key|secret[_-]?key|service[_-]?role/i);
    assert.doesNotMatch(source, /tus-js-client|x-signature|Supabase Storage/i);
});

test('the admin upload surface accepts only installable Android and iOS artifacts', () => {
    assert.match(adminView, /accept="\.apk,\.ipa"/);
    assert.equal(uploadModule.isSupportedMobileBuild('MathVerse.apk'), true);
    assert.equal(uploadModule.isSupportedMobileBuild('MathVerse.IPA'), true);
    assert.equal(uploadModule.isSupportedMobileBuild('MathVerse.aab'), false);
});

test('multipart worker slices the file, signs each part and reports complete progress', async () => {
    const file = new Blob([new Uint8Array(11 * 1024 * 1024)]);
    const signed = [];
    const uploaded = [];
    let progress = 0;

    await uploadModule.uploadR2Parts({
        file,
        signUrl: '/parts',
        partSize: 5 * 1024 * 1024,
        concurrency: 2,
        jsonRequest: async (_url, init) => {
            const part = JSON.parse(init.body).part_number;
            signed.push(part);
            return new Response(JSON.stringify({ url: `https://r2.example/part/${part}` }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        },
        putPart: async (url, blob, options) => {
            uploaded.push([url, blob.size]);
            options.onProgress(blob.size);
        },
        onProgress: loaded => { progress = loaded; },
    });

    assert.deepEqual([...signed].sort((a, b) => a - b), [1, 2, 3]);
    assert.deepEqual(uploaded.map(([, size]) => size).sort((a, b) => a - b), [1024 * 1024, 5 * 1024 * 1024, 5 * 1024 * 1024]);
    assert.equal(progress, file.size);
});

test('large transfer progress stays local while start and completion use foreground requests', () => {
    assert.match(source, /mathVerseForegroundFetch/);
    assert.match(source, /onProgress\(bytesUploaded, bytesTotal\)/);
    assert.match(source, /app-release-upload-bar/);
    assert.match(source, /beforeunload/);
    assert.doesNotMatch(source, /MathVerseProgress\.begin/);
});

test('R2 upload failures give useful, provider-accurate messages', () => {
    assert.match(uploadModule.uploadFailureMessage({ status: 403 }), /R2 upload authorization.*HTTP 403/i);
    assert.match(uploadModule.uploadFailureMessage({ status: 413 }), /configured game upload limit.*HTTP 413/i);
    assert.match(uploadModule.uploadFailureMessage({ status: 415 }), /valid APK or IPA/i);
    assert.match(uploadModule.uploadFailureMessage({}), /before Cloudflare R2 returned a response/i);
});
