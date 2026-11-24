import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const readability = read('public/css/interface-readability.css');
const palette = read('public/css/theme-colors.css');
const releaseUpload = read('resources/views/admin/app-releases/index.blade.php');

test('shared rectangular controls center their icon and label at every compact height', () => {
    assert.match(
        readability,
        /\.btn-mobile-ultra, \.btn-rect-primary, \.btn-rect-secondary, \.btn-rect-danger\):not\(\.hidden\) \{ display: inline-flex; align-items: center; justify-content: center; text-align: center;/,
    );
    assert.match(
        readability,
        /\.btn-mobile-ultra, \.btn-rect-primary, \.btn-rect-secondary, \.btn-rect-danger\)\[class~="block"\] \{ display: flex; width: 100%; \}/,
    );
});

test('dashboard theme toggle is a header-sized control instead of a fixed page control', () => {
    assert.match(readability, /\.theme-toggle \{ flex: 0 0 44px; width: 44px; height: 44px;/);
    assert.doesNotMatch(readability, /\.theme-toggle \{ position: fixed/);
});

test('light mode keeps action hovers visible for common and colored confirmation controls', () => {
    assert.match(palette, /\.btn-rect-primary:hover:not\(\[class\*="bg-"\]\) \{ background-color: #075985 !important; color: #ffffff !important;/);
    assert.match(palette, /\.btn-mobile-ultra:hover \{ background-image: linear-gradient\(135deg, #082f49, #0c4a6e, #0e7490\); color: #ffffff !important;/);
    assert.match(palette, /\.btn-rect-secondary:hover \{ background-color: #d9eff9; border-color: #0e7490; color: #0c4a6e !important;/);
    assert.match(palette, /\.btn-rect-primary\[class\*="bg-red"\]:hover \{ background-color: #991b1b !important; color: #ffffff !important;/);
});

test('the Android release file chooser uses a high-contrast white file label', () => {
    assert.match(releaseUpload, /file:font-black file:text-white/);
    assert.doesNotMatch(releaseUpload, /file:text-black/);
    assert.match(palette, /input\[type="file"\]::file-selector-button \{ background-color: #0e7490; color: #ffffff;/);
});
