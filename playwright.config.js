import { defineConfig, devices } from '@playwright/test';

const configuredBaseURL = String(process.env.STAGING_BASE_URL || '').trim();
if (process.env.CI && !configuredBaseURL) {
    throw new Error('STAGING_BASE_URL is required in CI.');
}
const baseURL = configuredBaseURL || 'http://127.0.0.1:8000';
if (process.env.CI && new URL(baseURL).protocol !== 'https:') {
    throw new Error('STAGING_BASE_URL must use HTTPS in CI.');
}

export default defineConfig({
    testDir: './tests/Browser',
    testMatch: '**/*.spec.js',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    timeout: 60_000,
    expect: {
        timeout: 10_000,
    },
    reporter: process.env.CI
        ? [['line'], ['html', { outputFolder: 'playwright-report', open: 'never' }]]
        : [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
    outputDir: 'test-results',
    use: {
        baseURL,
        actionTimeout: 10_000,
        navigationTimeout: 30_000,
        ignoreHTTPSErrors: false,
        permissions: ['notifications'],
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        {
            name: 'desktop-chromium',
            grepInvert: /@mobile/,
            use: {
                ...devices['Desktop Chrome'],
            },
        },
        {
            name: 'mobile-chromium',
            grep: /@mobile/,
            use: {
                ...devices['Pixel 5'],
            },
        },
    ],
});
