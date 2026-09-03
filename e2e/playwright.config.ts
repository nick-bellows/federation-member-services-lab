import { defineConfig, devices } from '@playwright/test';

/**
 * Federation (fork) end-to-end tests. They expect the compose stack to be up
 * (database, api, oidc, and `next dev` in the tooling container on :3000).
 *
 *   WEB_URL   base URL of the Next.js app       (default http://localhost:3000)
 *   OIDC_URL  browser-facing mock issuer         (default http://host.docker.internal:3004/default)
 */
export default defineConfig({
    testDir: './tests',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
    use: {
        baseURL: process.env.WEB_URL ?? 'http://localhost:3000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
