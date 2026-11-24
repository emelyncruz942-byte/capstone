import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const stylesheet = read('public/css/classroom-customization.css');

test('classroom pattern choices and selected banners remain visible in light mode', () => {
    for (const pattern of ['grid', 'stars', 'circuit', 'waves']) {
        assert.match(stylesheet, new RegExp(`classroom-banner\\[data-banner-pattern="${pattern}"\\]`));
        assert.match(stylesheet, new RegExp(`classroom-customization-pattern-preview\\[data-banner-pattern="${pattern}"\\]`));
    }
    assert.match(stylesheet, /\.classroom-banner \{\s+background-color: #e0f2fe !important;/);
    assert.match(stylesheet, /color-mix\(in srgb, var\(--classroom-accent\) 45%, transparent\)/);
});

test('teacher, student, and class-settings surfaces load the shared classroom visuals', () => {
    for (const path of [
        'resources/views/teacher/dashboard.blade.php',
        'resources/views/teacher/classes/show.blade.php',
        'resources/views/student/classes/show.blade.php',
        'resources/views/teacher/classes/settings.blade.php',
    ]) {
        assert.match(read(path), /css\/classroom-customization\.css/);
    }
    assert.match(read('resources/views/teacher/classes/settings.blade.php'), /classroom-customization-pattern-preview/);
});
