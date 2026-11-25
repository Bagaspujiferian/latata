import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: 1,
    reporter: 'list',
    timeout: 45_000,
    expect: {
        timeout: 10_000,
    },
    use: {
        baseURL: process.env.E2E_BASE_URL || 'http://latata.test',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        viewport: { width: 1366, height: 860 },
        ignoreHTTPSErrors: true,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
