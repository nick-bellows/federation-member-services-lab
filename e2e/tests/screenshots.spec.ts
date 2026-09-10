import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';

/**
 * Captures the README screenshots from the running stack. Not a test of
 * behaviour: skipped in CI, run by hand against a seeded stack
 * (NorthgateDemoSeeder) with the dev server or the release web image:
 *
 *   npx playwright test tests/screenshots.spec.ts          # WEB_URL defaults to http://localhost:3000
 *
 * Every capture waits for the content it is meant to show, and one
 * application is submitted from a fresh identity before the reviewer
 * screens so that the NASL queue has a row (C4: the earlier capture showed
 * an empty queue and an identity page still loading).
 */
test.skip(!!process.env.CI, 'screenshots are captured locally');

const out = (name: string) => path.resolve(__dirname, '..', '..', 'docs', 'assets', name);
const runId = Date.now().toString(36);

const personas = {
    jordan: { subject: 'mock|jordan', email: 'jordan.newcomer@northgate.example', name: 'Jordan Newcomer' },
    riley: { subject: 'mock|riley', email: 'riley.referee@northgate.example', name: 'Riley Referee' },
    applicant: { subject: `mock|shot-${runId}`, email: `shot-${runId}@northgate.example`, name: 'Casey Applicant' },
    naslAdmin: { subject: 'mock|nasl-admin', email: 'nasl-admin@northgate.example', name: 'NASL Admin' },
};

async function signIn(page: Page, persona: { subject: string; email: string; name: string }) {
    await page.goto('/en/member/sign-in');
    await page.getByRole('button', { name: 'Continue with Northgate ID' }).click();
    await expect(page).toHaveURL(/\/default\/authorize/);
    await page.locator('input[name="username"]').fill(persona.subject);
    await page.locator('textarea[name="claims"]').fill(JSON.stringify({ email: persona.email, email_verified: true, name: persona.name }));
    await page.locator('input[type="submit"]').click();
    await expect(page).toHaveURL(/\/en\/member$/);
    await expect(page.locator('#member-heading')).toContainText(persona.name);
}

async function signOut(page: Page) {
    await page.goto('/en/member');
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/en\/member\/sign-in$/);
}

// Fonts and the last layout pass; every capture has already waited for its content.
async function capture(page: Page, name: string) {
    await page.waitForTimeout(500);
    await page.screenshot({ path: out(name), fullPage: true });
}

test.use({ viewport: { width: 1200, height: 900 } });

test('capture member and reviewer screens', async ({ page }) => {
    await page.goto('/en/member/sign-in');
    await expect(page.getByRole('button', { name: 'Continue with Northgate ID' })).toBeVisible();
    await capture(page, 'member-sign-in.png');

    // The seeded newcomer: one draft application.
    await signIn(page, personas.jordan);
    await expect(page.locator('#scopes-heading')).toBeVisible();
    await expect(page.locator('#scopes-heading ~ ul li code').first()).toBeVisible();
    await capture(page, 'member-identity.png');

    await page.goto('/en/member/applications');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await capture(page, 'member-applications.png');

    await signOut(page);

    // The seeded referee: an approved application with reviewed documents and a full history.
    await signIn(page, personas.riley);
    await page.goto('/en/member/applications');
    await page.locator('tbody tr', { hasText: 'Approved' }).first().getByRole('link', { name: /Open/ }).click();
    await expect(page.getByRole('heading', { name: 'History' })).toBeVisible();
    await expect(page.locator('[data-status="approved"]').first()).toBeVisible();
    await capture(page, 'member-application.png');
    await signOut(page);

    // One submitted application from a fresh identity, so the reviewer's queue has a row.
    await signIn(page, personas.applicant);
    await page.goto('/en/member/applications/new');
    const naslOption = page.locator('#window option', { hasText: 'Northgate Adult Soccer League' });
    await page.getByLabel('Organization and season').selectOption((await naslOption.getAttribute('value')) ?? '');
    await page.getByRole('radio', { name: 'Participant' }).check();
    await page.getByLabel('Date of birth').fill('2000-07-14');
    await page.getByRole('button', { name: 'Start' }).click();
    await expect(page).toHaveURL(/\/en\/member\/applications\/\d+$/);
    for (const [type, name] of [
        ['Proof of age', 'proof-of-age.pdf'],
        ['Photo', 'photo.png'],
    ] as const) {
        await page.getByLabel(`Choose a file for ${type}`).setInputFiles({
            name,
            mimeType: name.endsWith('.png') ? 'image/png' : 'application/pdf',
            buffer: Buffer.from(`synthetic ${type} ${runId}`),
        });
        await expect(page.getByRole('status').filter({ hasText: 'Document recorded.' })).toBeVisible();
    }
    await page.getByRole('button', { name: 'Submit application' }).click();
    await expect(page.locator('[data-status="submitted"]').first()).toBeVisible();
    await signOut(page);

    await signIn(page, personas.naslAdmin);
    await page.goto('/en/member/review');
    await expect(page.locator('tbody tr').first()).toBeVisible();
    await capture(page, 'reviewer-queue.png');

    await page.goto('/en/member/windows');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByText('Northgate Adult Soccer League').first()).toBeVisible();
    await capture(page, 'reviewer-windows.png');
});
