import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const read = name => readFileSync(new URL(`../../public/css/${name}`, import.meta.url), 'utf8');
const colors = postcss.parse(read('theme-colors.css'));
const colorProperty = /^(?:--[\w-]+|color|background(?:-color|-image)?|(?:border|outline)(?:-(?:top|right|bottom|left))?-color|caret-color|-webkit-text-fill-color|color-scheme|(?:-webkit-)?box-shadow|text-shadow|filter|forced-color-adjust)$/;

test('theme palettes cannot change fonts, spacing, dimensions, shapes, layout or animation', () => {
    let count = 0;
    colors.walkDecls(declaration => {
        count++;
        assert.match(declaration.prop, colorProperty, `${declaration.prop} changes more than colors`);
        if (declaration.prop === 'filter') {
            assert.match(declaration.parent.selector, /calendar-picker-indicator/);
            assert.equal(declaration.value, 'none');
        }
    });
    assert.ok(count > 150, 'cover the shared components and legacy utilities');
    colors.walkRules(rule => {
        for (const selector of rule.selectors) {
            assert.ok(selector === ':root' || selector.startsWith('html[data-theme="light"]'), `Unscoped palette: ${selector}`);
        }
    });
});

test('photo widgets and common readability styles keep one geometry in both modes', () => {
    for (const file of ['avatars.css', 'avatar-cropper.css', 'interface-readability.css']) {
        postcss.parse(read(file)).walkRules(rule => {
            if (!/data-theme=["'](?:light|dark)["']/.test(rule.selector)) return;
            rule.walkDecls(declaration => assert.match(declaration.prop, colorProperty, `${file}: ${declaration.prop} differs between themes`));
        });
    }
});

test('light-mode translations retain baseline gradient and shadow geometry', () => {
    const baseline = new Map();
    const geometry = value => value.replace(/#[\da-f]{3,8}\b|rgba?\([^)]*\)/gi, 'COLOR');
    postcss.parse(read('style.css')).walkRules(rule => {
        rule.each(declaration => {
            if (declaration.type === 'decl' && ['background', 'background-image', 'box-shadow', '-webkit-box-shadow', 'text-shadow'].includes(declaration.prop)) {
                const key = `${rule.selector}|${declaration.prop}`;
                baseline.set(key, [...(baseline.get(key) || []), declaration.value]);
            }
        });
    });
    colors.walkRules(rule => {
        const selector = rule.selectors.map(s => s.replace(/^html\[data-theme="light"\] /, '')).join(',\n');
        rule.each(declaration => {
            if (declaration.type !== 'decl') return;
            const original = baseline.get(`${selector}|${declaration.prop}`);
            if (!original || !/gradient\(|shadow/.test(original.join(' ') + declaration.prop)) return;
            assert.ok(original.some(value => geometry(declaration.value) === geometry(value)), `${selector}: ${declaration.prop} changed gradient or shadow geometry`);
        });
    });
});
