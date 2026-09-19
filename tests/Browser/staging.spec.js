import { expect, test } from '@playwright/test';

const runtimeFailures = new WeakMap();

const accountVariables = {
    student: ['STAGING_STUDENT_EMAIL', 'STAGING_STUDENT_PASSWORD'],
    teacher: ['STAGING_TEACHER_EMAIL', 'STAGING_TEACHER_PASSWORD'],
    admin: ['STAGING_ADMIN_EMAIL', 'STAGING_ADMIN_PASSWORD'],
};

function requiredEnvironment(name) {
    const value = String(process.env[name] || '').trim();
    if (!value) throw new Error(`Missing required staging environment variable: ${name}`);
    return value;
}

async function login(page, role) {
    const [emailVariable, passwordVariable] = accountVariables[role];
    const email = requiredEnvironment(emailVariable);
    const password = requiredEnvironment(passwordVariable);

    await page.goto('/');
    const form = page.locator('form[action="/login"]');
    await expect(form).toBeVisible();
    await form.locator('input[name="email"]').fill(email);
    await form.locator('input[name="password"]').fill(password);
    await form.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(new RegExp(`/${role}/dashboard(?:\\?|$)`));
    await expect(page.locator('#dashboard-content')).toBeVisible();
}

async function openPage(page, path) {
    const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
    expect(response, `No document response for ${path}`).not.toBeNull();
    expect(response.status(), `${path} returned ${response.status()}`).toBeLessThan(500);
}

test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page }) => {
    const failures = [];
    runtimeFailures.set(page, failures);
    page.on('pageerror', error => failures.push(`pageerror: ${error.message}`));
    page.on('console', message => {
        if (message.type() === 'error') failures.push(`console: ${message.text()}`);
    });
    page.on('response', response => {
        if (response.status() >= 500) failures.push(`http ${response.status()}: ${response.url()}`);
    });
});

test.afterEach(async ({ page }) => {
    const failures = runtimeFailures.get(page) || [];
    expect(failures, `Unexpected browser/runtime failures:\n${failures.join('\n')}`).toEqual([]);
});

test('student classroom, notifications, push, and Learning Hub flow', async ({ page }) => {
    test.setTimeout(120_000);
    await login(page, 'student');

    await openPage(page, '/student/dashboard?section=class');
    await expect(page.getByText('My Enrolled Classes', { exact: true })).toBeVisible();
    const classLink = page.getByRole('link', { name: /View Quizzes & Analytics/i }).first();
    await expect(classLink, 'The staging student must be enrolled in at least one class.').toBeVisible();

    const notificationSnapshot = await page.evaluate(async () => {
        const response = await fetch('/notifications/snapshot', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        return { status: response.status, body: await response.json().catch(() => ({})) };
    });
    expect(notificationSnapshot.status).toBe(200);
    expect(notificationSnapshot.body.html).toContain('data-notification-root');
    await expect(page.locator('[data-notification-root]').first()).toBeVisible();

    await classLink.click();
    await expect(page.getByText('Assigned & Active Quizzes')).toBeVisible();
    await expect(page.getByText('My Class Analytics')).toBeVisible();

    await openPage(page, '/student/learning-hub');
    const learningHub = page.getByTestId('student-learning-hub');
    await expect(learningHub).toHaveAttribute('data-configured', 'true');
    await expect(learningHub.getByText('Overall Mastery', { exact: true })).toBeVisible();
    expect(await learningHub.innerText()).not.toMatch(/supabase/i);

    await openPage(page, '/student/learning-hub/practice?mode=daily');
    await expect(page.locator('#practice-arena')).toBeVisible();
    const privateQuestionState = await page.locator('#practice-initial-state').evaluate(element => element.content.textContent);
    expect(privateQuestionState).not.toContain('"correct_answer"');
    expect(privateQuestionState).not.toContain('"explanation"');

    const answerControl = page.locator('#practice-answer-area').locator('input, button').first();
    await expect(answerControl).toBeVisible();
    const hintButton = page.locator('#practice-hint-button');
    if (await hintButton.isVisible() && await hintButton.isEnabled()) {
        await hintButton.click();
        await expect(page.locator('#practice-hint-list li').first()).toBeVisible();
    } else {
        await expect(page.locator('#practice-hint-list li').first(), 'A resumed question with no hint button must already show its hints.').toBeVisible();
    }

    const numberAnswer = page.locator('#practice-number-answer');
    if (await numberAnswer.isVisible()) {
        await numberAnswer.fill('0');
    } else {
        await page.locator('#practice-answer-area .practice-choice').first().click();
    }
    await page.locator('#practice-submit-button').click();
    await expect(page.locator('#practice-feedback')).toBeVisible();
    await expect(page.locator('#practice-feedback-answer')).not.toBeEmpty();

    await openPage(page, '/student/dashboard?section=security');
    await expect(page.locator('#sec-security')).toBeVisible();
    const pushStatus = page.locator('[data-push-status]').first();
    const pushButton = page.locator('[data-push-toggle]').first();
    await expect(pushStatus).not.toContainText('Checking whether', { timeout: 15_000 });
    await expect(pushButton).toBeEnabled();
    const pushCapabilities = await page.evaluate(() => ({
        secure: window.isSecureContext,
        serviceWorker: 'serviceWorker' in navigator,
        pushManager: 'PushManager' in window,
        notifications: 'Notification' in window,
    }));
    expect(pushCapabilities).toEqual({ secure: true, serviceWorker: true, pushManager: true, notifications: true });
    await expect.poll(async () => page.evaluate(async () => Boolean(await navigator.serviceWorker.getRegistration())), {
        message: 'The MathVerse notification service worker should register.',
        timeout: 15_000,
    }).toBe(true);
});

test('student completes one verified turn in every arcade game', async ({ page }) => {
    test.setTimeout(120_000);
    await login(page, 'student');

    await openPage(page, '/student/games');
    const hub = page.getByTestId('arcade-hub');
    await expect(hub).toHaveAttribute('data-configured', 'true');
    await expect(hub.locator('.arcade-game-card')).toHaveCount(4);
    await expect(hub.locator('[data-game-key="fraction-comparison"]')).toHaveCount(0);
    await expect(hub).toContainText('never award Learning Hub XP or trophies');

    for (const gameKey of ['mental-arithmetic', 'equation-balance', 'pattern-pulse']) {
        await openPage(page, `/student/games/${gameKey}`);
        const game = page.getByTestId('arcade-game');
        await expect(game).toHaveAttribute('data-game-key', gameKey);
        await expect(game).toHaveAttribute('data-configured', 'true');
        await page.locator('#arcade-start').click();
        await expect(page.locator('#arcade-submit')).toBeEnabled();

        if (await page.locator('#arcade-choice-options').isVisible()) {
            await page.locator('#arcade-choice-options .arcade-choice-button').first().click();
        } else {
            await page.locator('#arcade-input').fill('0');
        }
        await page.locator('#arcade-submit').click();
        await expect(page.locator('#arcade-answers')).toHaveText('1');
        await expect(page.locator('#arcade-feedback')).not.toHaveAttribute('data-tone', 'neutral');

        await page.locator('#arcade-end').click();
        await expect(page.locator('#arcadeResultModal')).toBeVisible();
        await expect(page.locator('#arcade-result-summary')).toContainText('You scored');
    }

    await openPage(page, '/student/games/number-guess');
    const startButton = page.locator('#number-guess-start');
    await expect(startButton).toBeEnabled();
    const restarting = /restart/i.test(await startButton.innerText());
    await startButton.click();
    if (restarting) {
        await expect(page.locator('#numberGuessEndModal')).toBeVisible();
        await page.locator('#number-guess-confirm-end').click();
    }
    await expect(page.locator('#number-guess-input')).toBeEnabled();
    await page.locator('#number-guess-input').fill('50');
    await page.locator('#number-guess-submit').click();
    await expect(page.locator('#number-guess-tries')).toHaveText('1');
    await page.locator('#number-guess-end').click();
    await expect(page.locator('#numberGuessEndModal')).toBeVisible();
    await page.locator('#number-guess-confirm-end').click();
    await expect(page.locator('#numberGuessResultModal')).toBeVisible();
});

test('teacher quiz surfaces and Learning Hub analytics are live', async ({ page }) => {
    await login(page, 'teacher');

    await openPage(page, '/teacher/quizzes');
    await expect(page.getByText('My VR Quiz Bees')).toBeVisible();
    await expect(page.getByRole('button', { name: /Create Quiz/i })).toBeVisible();

    await openPage(page, '/teacher/quiz-library');
    await expect(page.getByText('Shared Quiz Library')).toBeVisible();
    await expect(page.locator('input[name="search"]')).toBeVisible();

    await openPage(page, '/teacher/learning-hub');
    const analytics = page.getByTestId('teacher-learning-hub');
    await expect(analytics).toHaveAttribute('data-configured', 'true');
    await expect(analytics.getByText('Class mastery', { exact: true })).toBeVisible();
    await expect(analytics.getByText('Weak Topics', { exact: true })).toBeVisible();
    await expect(analytics.getByText('Hint usage', { exact: true })).toBeVisible();
    await expect(analytics.getByText('Improvement', { exact: true })).toBeVisible();
    await expect(analytics.getByText('Student Mastery and Activity', { exact: true })).toBeVisible();
    expect(await analytics.innerText()).not.toMatch(/supabase/i);
});

test('admin moderation, durable audits, delivery health, and deployment identity are live', async ({ page }) => {
    await login(page, 'admin');

    await openPage(page, '/admin/quizzes');
    await expect(page.getByText('My VR Quiz Bees')).toBeVisible();
    await openPage(page, '/admin/quiz-reports');
    await expect(page.getByRole('heading', { name: 'Quiz Reports', level: 1 })).toBeVisible();

    await openPage(page, '/admin/dashboard?section=audit&audit_category=security');
    const auditSection = page.locator('#sec-audit');
    await expect(auditSection).toBeVisible();
    await expect(auditSection.getByText('Accountability Audit Log')).toBeVisible();
    const auditForm = auditSection.locator('form.audit-filter-grid');
    await auditForm.locator('input[name="audit_search"]').fill(requiredEnvironment('STAGING_ADMIN_EMAIL'));
    await auditForm.locator('select[name="audit_category"]').selectOption('security');
    await auditForm.locator('select[name="audit_outcome"]').selectOption('succeeded');
    await auditForm.getByRole('button', { name: 'Filter' }).click();
    await page.waitForURL(/audit_search=.*audit_category=security.*audit_outcome=succeeded|audit_outcome=succeeded.*audit_category=security/);
    await expect(page.locator('#sec-audit')).toBeVisible();

    const allStreamsForm = page.locator('#sec-audit form.audit-filter-grid');
    await allStreamsForm.locator('input[name="audit_search"]').fill('');
    await allStreamsForm.locator('select[name="audit_category"]').selectOption('all');
    await allStreamsForm.locator('select[name="audit_outcome"]').selectOption('');
    await allStreamsForm.getByRole('button', { name: 'Filter' }).click();
    await page.waitForURL(url => url.searchParams.get('audit_category') === 'all');
    await expect(page.locator('#sec-audit select[name="audit_category"]')).toHaveValue('all');
    const nextAuditPage = page.getByRole('navigation', { name: 'Audit pages' }).getByRole('link', { name: 'Next' });
    if (await nextAuditPage.count()) await expect(nextAuditPage).toHaveAttribute('href', /audit_category=all/);

    await openPage(page, '/admin/system-health');
    const health = page.getByTestId('system-health');
    await expect(health).toBeVisible();
    await expect(health.locator('.health-card')).toHaveCount(7);
    await expect(health).not.toHaveAttribute('data-overall-status', 'critical');
    const criticalChecks = await health.locator('.health-card[data-status="critical"]').count();
    expect(criticalChecks, 'No staging health check should be critical.').toBe(0);
    await expect(health.locator('[data-check="deliveries"]')).toContainText('Email and Browser Alerts');
    await expect(health.locator('[data-check="database"]')).toContainText('Primary Data Service');
    await expect(health.locator('[data-check="deployment"] .health-metric')).not.toHaveText('not provided');
});

test('mobile student Join button remains complete and inside the viewport @mobile', async ({ page }) => {
    await login(page, 'student');
    await openPage(page, '/student/dashboard?section=class');
    const form = page.locator('.student-join-form');
    const input = form.locator('input[name="join_code"]');
    const button = form.getByRole('button', { name: 'Join', exact: true });
    await expect(form).toBeVisible();
    await expect(input).toBeVisible();
    await expect(button).toBeVisible();
    await expect(button).toHaveText('Join');

    const layout = await button.evaluate((element) => {
        const buttonRect = element.getBoundingClientRect();
        const inputRect = element.form.querySelector('input[name="join_code"]').getBoundingClientRect();
        return {
            viewportWidth: window.innerWidth,
            buttonLeft: buttonRect.left,
            buttonRight: buttonRect.right,
            buttonWidth: buttonRect.width,
            inputLeft: inputRect.left,
            inputRight: inputRect.right,
            overlap: !(buttonRect.bottom <= inputRect.top || inputRect.bottom <= buttonRect.top),
        };
    });
    expect(layout.buttonLeft).toBeGreaterThanOrEqual(0);
    expect(layout.buttonRight).toBeLessThanOrEqual(layout.viewportWidth);
    expect(layout.buttonWidth).toBeGreaterThan(80);
    expect(layout.inputLeft).toBeGreaterThanOrEqual(0);
    expect(layout.inputRight).toBeLessThanOrEqual(layout.viewportWidth);
    expect(layout.overlap).toBe(false);
});
