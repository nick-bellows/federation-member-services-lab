import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';

/**
 * Captures the README screenshots from the running stack. Not a test of
 * behaviour: skipped in CI, run by hand after registration-review.spec.ts
 * (or any run that left the seeded Jordan with at least one application).
 *
 *   npx playwright test tests/screenshots.spec.ts
 */
test.skip(!!process.env.CI, 'screenshots are captured locally');

const out = (name: string) => path.resolve(__dirname, '..', '..', 'docs', 'assets', name);

const personas = {
    jordan: { subject: 'mock|jordan', email: 'jordan.newcomer@northgate.example', name: 'Jordan Newcomer' },
    naslAdmin: { subject: 'mock|nasl-admin', email: 'nasl-admin@northgate.example', name: 'NASL Admin' },
};

async function signIn(page: Page, persona: { subject: string; email: string; name: string }) {
    await page.goto('/en/member/sign-in');
    await page.getByRole('button', { name: 'Continue with Northgate ID' }).click();
    await page.locator('input[name="username"]').fill(persona.subject);
    await page.locator('textarea[name="claims"]').fill(JSON.stringify({ email: persona.email, email_verified: true, name: persona.name }));
    await page.locator('input[type="submit"]').click();
    await expect(page).toHaveURL(/\/en\/member$/);
}

test.use({ viewport: { width: 1200, height: 900 } });

test('capture member and reviewer screens', async ({ page }) => {
    await page.goto('/en/member/sign-in');
    await page.screenshot({ path: out('member-sign-in.png'), fullPage: true });

    await signIn(page, personas.jordan);
    await page.screenshot({ path: out('member-identity.png'), fullPage: true });

    await page.goto('/en/member/applications');
    await page.screenshot({ path: out('member-applications.png'), fullPage: true });

    const firstRow = page.locator('tbody tr').first();
    await firstRow.getByRole('link', { name: /Open/ }).click();
    await expect(page.getByRole('heading', { name: 'History' })).toBeVisible();
    await page.screenshot({ path: out('member-application.png'), fullPage: true });

    await page.goto('/en/member');
    await page.getByRole('button', { name: 'Sign out' }).click();

    await signIn(page, personas.naslAdmin);
    await page.goto('/en/member/review');
    await page.screenshot({ path: out('reviewer-queue.png'), fullPage: true });

    await page.goto('/en/member/windows');
    await page.screenshot({ path: out('reviewer-windows.png'), fullPage: true });
});
