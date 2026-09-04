import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

/**
 * Records the demo (docs/assets/demo.webm): one applicant starts, completes
 * and submits an application; one reviewer takes it under review, accepts the
 * documents, approves it and refreshes the applicant's credentials; the
 * applicant sees the decision, the history and the participation status.
 *
 * Not a test of behaviour (registration-review.spec.ts is): skipped in CI,
 * run by hand against a seeded stack, with pauses so a viewer can follow.
 *
 *   WEB_URL=http://localhost:3010 DEMO_SUBJECT=mock|alex DEMO_EMAIL=alex.participant@northgate.example \
 *   DEMO_NAME="Alex Participant" npx playwright test tests/demo.spec.ts
 *
 * The applicant persona must be known to the Learning Center mock (alex, sam,
 * riley) for the participation panel to show a verdict; a fresh identity
 * shows "unknown" with its reason, which is also true and also worth seeing.
 */
test.skip(!!process.env.CI, 'the demo is recorded locally');

const out = path.resolve(__dirname, '..', '..', 'docs', 'assets');
const applicant = {
    subject: process.env.DEMO_SUBJECT ?? 'mock|alex',
    email: process.env.DEMO_EMAIL ?? 'alex.participant@northgate.example',
    name: process.env.DEMO_NAME ?? 'Alex Participant',
};
const role = process.env.DEMO_ROLE ?? 'Participant';
const organization = process.env.DEMO_ORGANIZATION ?? 'Northgate Adult Soccer League';
const reviewer = { subject: 'mock|nasl-admin', email: 'nasl-admin@northgate.example', name: 'NASL Admin' };
const pause = Number(process.env.DEMO_PAUSE_MS ?? 900);

test.use({
    viewport: { width: 1280, height: 800 },
    video: { mode: 'on', size: { width: 1280, height: 800 } },
});

async function beat(page: Page, ms = pause) {
    await page.waitForTimeout(ms);
}

async function signIn(page: Page, persona: { subject: string; email: string; name: string }) {
    await page.goto('/en/member/sign-in');
    await beat(page);
    await page.getByRole('button', { name: 'Continue with Northgate ID' }).click();
    await expect(page).toHaveURL(/\/default\/authorize/);
    await page.locator('input[name="username"]').fill(persona.subject);
    await page.locator('textarea[name="claims"]').fill(JSON.stringify({ email: persona.email, email_verified: true, name: persona.name }));
    await beat(page, 400);
    await page.locator('input[type="submit"]').click();
    await expect(page).toHaveURL(/\/en\/member$/);
    await beat(page);
}

async function signOut(page: Page) {
    await page.goto('/en/member');
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/en\/member\/sign-in$/);
}

test('the applicant and reviewer journey, recorded', async ({ page }) => {
    // Applicant
    await signIn(page, applicant);
    await page.goto('/en/member/applications/new');
    await beat(page);
    const option = page.locator('#window option', { hasText: organization });
    await page.getByLabel('Organization and season').selectOption((await option.getAttribute('value')) ?? '');
    await page.getByRole('radio', { name: role }).check();
    await page.getByLabel('Date of birth').fill('1998-04-12');
    await beat(page, 500);
    await page.getByRole('button', { name: 'Start' }).click();
    await expect(page).toHaveURL(/\/en\/member\/applications\/\d+$/);
    await beat(page);

    const fileInputs = page.getByLabel(/^Choose a file for /);
    const count = await fileInputs.count();
    for (let i = 0; i < count; i++) {
        const input = page.getByLabel(/^Choose a file for /).nth(i);
        const label = (await input.getAttribute('aria-label')) ?? `document-${i}`;
        const name = label.replace(/^Choose a file for /, '').toLowerCase().replace(/\s+/g, '-');
        await input.setInputFiles({ name: `${name}.pdf`, mimeType: 'application/pdf', buffer: Buffer.from(`synthetic ${name} ${Date.now()}`) });
        await expect(page.getByRole('status').filter({ hasText: 'Document recorded.' })).toBeVisible();
        await beat(page, 500);
    }

    await page.getByRole('button', { name: 'Submit application' }).click();
    await expect(page.locator('[data-status="submitted"]').first()).toBeVisible();
    await beat(page, 1500);
    await signOut(page);

    // Reviewer
    await signIn(page, reviewer);
    await page.getByRole('link', { name: 'Review queue' }).click();
    await expect(page).toHaveURL(/\/en\/member\/review$/);
    await beat(page);
    const row = page.locator('tbody tr', { hasText: applicant.name }).first();
    await row.getByRole('link', { name: /Review/ }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(`Application from ${applicant.name}`);
    await beat(page);
    await page.getByRole('button', { name: 'Start review' }).click();
    await expect(page.locator('[data-status="under_review"]').first()).toBeVisible();
    await beat(page);

    const accepts = page.getByRole('button', { name: /^Accept / });
    const acceptCount = await accepts.count();
    for (let i = 0; i < acceptCount; i++) {
        await page.getByRole('button', { name: /^Accept / }).first().click();
        await expect(page.getByRole('status').filter({ hasText: 'Document decision saved.' })).toBeVisible();
        await beat(page, 500);
    }

    await page.getByRole('button', { name: 'Approve' }).click();
    await expect(page.locator('[data-status="approved"]').first()).toBeVisible();
    await beat(page);
    await page.getByRole('button', { name: 'Refresh from Learning Center' }).click();
    await expect(page.locator('[data-participation]')).toBeVisible();
    await beat(page, 1500);
    await signOut(page);

    // Applicant sees the decision
    await signIn(page, applicant);
    await page.goto('/en/member/applications');
    await beat(page);
    const approved = page.locator('tbody tr', { has: page.locator('[data-status="approved"]') }).first();
    await approved.getByRole('link', { name: /Open/ }).click();
    await expect(page.getByRole('heading', { name: 'Participation' })).toBeVisible();
    await beat(page, 2500);

    mkdirSync(out, { recursive: true });
    const video = page.video();
    await page.close();
    if (video) {
        await video.saveAs(path.join(out, 'demo.webm'));
    }
});
