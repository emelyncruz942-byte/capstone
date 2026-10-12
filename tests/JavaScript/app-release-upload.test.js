import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(
    new URL('../../resources/js/app-release-upload.js', import.meta.url),
    'utf8',
);
const adminView = readFileSync(
    new URL('../../resources/views/admin/app-releases/index.blade.php', import.meta.url),
    'utf8',
);
const uploadModule = await import(
    new URL('../../resources/js/app-release-upload.js', import.meta.url)
);

test('mobile builds use signed resumable uploads with safe Supabase metadata', () => {
    assert.match(source, /6 \* 1024 \* 1024/);
    assert.match(source, /retryDelays:\s*\[0, 3000, 5000, 10000, 20000\]/);
    assert.match(source, /'x-signature':\s*uploadDetails\.token/);
    assert.match(source, /bucketName:\s*uploadDetails\.bucket/);
    assert.match(source, /objectName:\s*uploadDetails\.path/);
    assert.match(source, /cacheControl:\s*'0'/);
    assert.match(source, /removeFingerprintOnSuccess:\s*true/);
    assert.match(source, /mathverse-release:\$\{uploadDetails\.bucket\}\/\$\{uploadDetails\.path\}/);
    assert.doesNotMatch(source, /service[_-]?role|service[_-]?key/i);
});

test('the admin upload surface accepts only installable Android and iOS artifacts', () => {
    assert.match(adminView, /accept="\.apk,\.ipa"/);
    assert.match(source, /\\\.\(\?:apk\|ipa\)\$/i);
    assert.doesNotMatch(adminView, /\.zip|\.exe|\.aab/i);
});

test('large transfer progress stays local while short server mutations use the global bar', () => {
    assert.match(source, /mathVerseForegroundFetch/);
    assert.match(source, /onProgress\(bytesUploaded, bytesTotal\)/);
    assert.match(source, /app-release-upload-bar/);
    assert.match(source, /beforeunload/);
    assert.doesNotMatch(source, /MathVerseProgress\.begin/);
});

test('signed resumable failures explain authorization and storage-size errors', () => {
    const response = status => ({ originalResponse: { getStatus: () => status } });

    assert.match(uploadModule.uploadFailureMessage(response(403)), /authorization.*HTTP 403/i);
    assert.match(uploadModule.uploadFailureMessage(response(413)), /file-size limit.*HTTP 413/i);
    assert.match(uploadModule.uploadFailureMessage(response(415)), /valid APK or IPA/i);
    assert.match(uploadModule.uploadFailureMessage({}), /before Supabase returned a response/i);
});
