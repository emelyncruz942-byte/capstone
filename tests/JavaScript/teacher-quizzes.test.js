import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(
    new URL('../../public/js/teacher-quizzes.js', import.meta.url),
    'utf8',
);

function quizBuilder() {
    const appended = [];
    const builder = {
        children: [],
        appendChild(element) {
            this.children.push(element);
            appended.push(element);
        },
    };
    const context = vm.createContext({
        document: {
            addEventListener: () => {},
            createElement: () => ({ className: '', innerHTML: '' }),
            getElementById: id => id === 'questions-builder' ? builder : null,
        },
        onMathVerseReady: () => {},
    });
    vm.runInContext(source, context, { filename: 'teacher-quizzes.js' });

    return { context, appended };
}

test('quiz questions use a multiline textarea while answer options stay single-line', () => {
    const { context, appended } = quizBuilder();

    context.addQuestionBlock('How many are left?\nShow your reasoning.', ['1', '2', '3', '4'], 1);

    assert.equal(appended.length, 1);
    assert.match(
        appended[0].innerHTML,
        /<textarea name="questions\[0\]\[question\]" rows="4" maxlength="1000"[\s\S]*How many are left\?\nShow your reasoning\.<\/textarea>/,
    );
    assert.doesNotMatch(appended[0].innerHTML, /<input type="text" name="questions\[0\]\[question\]"/);
    assert.equal(
        (appended[0].innerHTML.match(/<input type="text" name="questions\[0\]\[options\]\[\]"/g) ?? []).length,
        4,
    );
});

test('quiz question text is escaped safely inside its textarea', () => {
    const { context, appended } = quizBuilder();

    context.addQuestionBlock('</textarea><script>alert("x")</script>', ['', '', '', ''], 0);

    assert.match(
        appended[0].innerHTML,
        /&lt;\/textarea&gt;&lt;script&gt;alert\(&quot;x&quot;\)&lt;\/script&gt;/,
    );
    assert.doesNotMatch(appended[0].innerHTML, /<script>/);
});
