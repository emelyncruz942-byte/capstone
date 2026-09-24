import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(
    new URL('../../public/js/teacher-classroom.js', import.meta.url),
    'utf8',
);

function classroom(fetchImpl) {
    const context = vm.createContext({
        fetch: fetchImpl,
        csrfToken: () => 'test-token',
        onMathVerseReady: () => {},
        document: { addEventListener: () => {} },
        clearInterval: () => {},
        setInterval: () => 1,
        setTimeout: (callback) => {
            callback();
            return 1;
        },
        CustomEvent: class {},
        Error,
        Promise,
    });
    vm.runInContext(source, context, { filename: 'teacher-classroom.js' });
    return context;
}

function response(status, payload) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => payload,
    };
}

test('manual quiz actions retry one transient server response safely', async () => {
    const requests = [];
    const context = classroom(async (url, options) => {
        requests.push({ url, options });
        return requests.length === 1
            ? response(503, { message: 'Please retry.' })
            : response(200, { success: true, changed: false, message: 'Already active.' });
    });

    const result = await context.submitQuizAction('class-1', 'quiz-1', 'start');

    assert.equal(result.message, 'Already active.');
    assert.equal(requests.length, 2);
    assert.equal(requests[0].url, '/teacher/classes/class-1/quizzes/quiz-1/start');
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'test-token');
});

test('manual quiz actions retry one network interruption', async () => {
    let attempts = 0;
    const context = classroom(async () => {
        attempts += 1;
        if (attempts === 1) throw new Error('Network unavailable.');
        return response(200, { success: true, message: 'Quiz ended.' });
    });

    const result = await context.submitQuizAction('class-1', 'quiz-1', 'end');

    assert.equal(result.message, 'Quiz ended.');
    assert.equal(attempts, 2);
});

test('manual quiz actions do not retry validation failures', async () => {
    let attempts = 0;
    const context = classroom(async () => {
        attempts += 1;
        return response(422, { message: 'This quiz assignment is already past due.' });
    });

    await assert.rejects(
        context.submitQuizAction('class-1', 'quiz-1', 'start'),
        /already past due/,
    );
    assert.equal(attempts, 1);
});
