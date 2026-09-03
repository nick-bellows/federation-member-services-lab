import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * The registration-review slice in a real browser, against the seeded
 * Northgate stack (NorthgateDemoSeeder) and the mock OIDC provider.
 *
 *   applicant (fresh identity): start → details → documents (hashed locally) → submit
 *   reviewer (NASL admin): queue → start review → accept a document → approve
 *   applicant: sees "approved" and the history
 *
 * The applicant is a fresh identity per run (see runId below).
 */
// A fresh applicant per run: the mock provider issues an identity for any
// subject and the API provisions the user on first sign-in, so the journey
// never collides with an approved application from an earlier run (one live
// application per person, organization, season and role).
const runId = Date.now().toString(36);
const personas = {
    applicant: { subject: `mock|e2e-${runId}`, email: `e2e-${runId}@northgate.example`, name: `E2E Applicant ${runId}` },
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
}

async function signOut(page: Page) {
    await page.goto('/en/member');
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/en\/member\/sign-in$/);
}

async function expectAccessible(page: Page) {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');

    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
}

// React hydration mismatches and uncaught page errors fail the journey; the
// cold-clone verification (2026-09-02) found a date that hydrated with a
// different calendar day than the server rendered.
let runtimeErrors: string[] = [];
test.beforeEach(({ page }) => {
    runtimeErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error' && /hydrat|did not match|server.*client/i.test(message.text())) {
            runtimeErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => runtimeErrors.push(error.message));
});
test.afterEach(() => {
    expect(runtimeErrors, 'pages must hydrate without React or runtime errors').toEqual([]);
});

test.describe.serial('registration review slice', () => {
    test('an applicant starts, completes and submits an application', async ({ page }) => {
        await signIn(page, personas.applicant);

        await page.goto('/en/member/applications/new');
        await expectAccessible(page);
        const naslOption = page.locator('#window option', { hasText: 'Northgate Adult Soccer League' });
        await page.getByLabel('Organization and season').selectOption((await naslOption.getAttribute('value')) ?? '');
        await page.getByRole('radio', { name: 'Participant' }).check();
        await page.getByLabel('Date of birth').fill('2001-03-09');
        await page.getByRole('button', { name: 'Start' }).click();

        await expect(page).toHaveURL(/\/en\/member\/applications\/\d+$/);
        await expect(page.getByRole('heading', { level: 1 })).toContainText('Participant application');
        await expect(page.locator('[data-status="draft"]').first()).toBeVisible();
        await expectAccessible(page);

        // Two required documents, hashed in the browser, metadata only.
        for (const [type, name] of [
            ['Proof of age', 'proof-of-age.pdf'],
            ['Photo', 'photo.png'],
        ] as const) {
            await page.getByLabel(`Choose a file for ${type}`).setInputFiles({
                name,
                mimeType: name.endsWith('.png') ? 'image/png' : 'application/pdf',
                buffer: Buffer.from(`synthetic ${type} ${Date.now()}`),
            });
            await expect(page.getByRole('status').filter({ hasText: 'Document recorded.' })).toBeVisible();
        }

        await expect(page.locator('[data-status="pending"]')).toHaveCount(2);

        await page.getByRole('button', { name: 'Submit application' }).click();
        await expect(page.locator('[data-status="submitted"]').first()).toBeVisible();
        await expect(page.getByRole('heading', { name: 'History' })).toBeVisible();
        await expect(page.getByText('Submitted', { exact: true }).first()).toBeVisible();

        await signOut(page);
    });

    test('a reviewer works the queue and approves', async ({ page }) => {
        await signIn(page, personas.naslAdmin);

        await page.getByRole('link', { name: 'Review queue' }).click();
        await expect(page).toHaveURL(/\/en\/member\/review$/);
        await expectAccessible(page);

        const row = page.locator('tbody tr', { hasText: personas.applicant.name }).first();
        await expect(row).toBeVisible();
        await row.getByRole('link', { name: /Review/ }).click();

        await expect(page.getByRole('heading', { level: 1 })).toHaveText(`Application from ${personas.applicant.name}`);
        await page.getByRole('button', { name: 'Start review' }).click();
        await expect(page.locator('[data-status="under_review"]').first()).toBeVisible();
        await expectAccessible(page);

        await page.getByRole('button', { name: 'Accept Proof of age' }).click();
        await expect(page.getByRole('status').filter({ hasText: 'Document decision saved.' })).toBeVisible();
        await expect(page.locator('[data-status="accepted"]')).toHaveCount(1);

        await page.getByRole('button', { name: 'Approve' }).click();
        await expect(page.locator('[data-status="approved"]').first()).toBeVisible();

        // A fresh identity is unknown to the Learning Center mock: the panel says
        // so, with the reason, and a refresh asks the provider again.
        await expect(page.getByRole('heading', { name: 'Participation' })).toBeVisible();
        await expect(page.locator('[data-participation]')).toHaveAttribute('data-participation', 'unknown');
        await page.getByRole('button', { name: 'Refresh from Learning Center' }).click();
        await expect(page.getByText('The Learning Center has no record for this person.')).toBeVisible();
        await expectAccessible(page);

        await signOut(page);
    });

    test('the applicant sees the decision and the history', async ({ page }) => {
        await signIn(page, personas.applicant);
        await page.goto('/en/member/applications');

        const row = page.locator('tbody tr', { has: page.locator('[data-status="approved"]') }).first();
        await expect(row).toBeVisible();
        await row.getByRole('link', { name: /Open/ }).click();

        await expect(page.getByText('Your registration for this season and role is approved.')).toBeVisible();
        const history = page.getByRole('list').filter({ hasText: 'Application started' });
        await expect(history).toContainText('Review started');
        await expect(history).toContainText('Document reviewed');
        await expect(history).toContainText('Approved');
        await expect(page.getByRole('button', { name: 'Submit application' })).toHaveCount(0);
        await expect(page.getByRole('heading', { name: 'Participation' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Refresh from Learning Center' })).toHaveCount(0);
        await expectAccessible(page);

        await signOut(page);
    });

    test('an organization administrator can open a registration window', async ({ page }) => {
        await signIn(page, personas.naslAdmin);
        await page.getByRole('link', { name: 'Registration windows' }).click();
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Registration windows');
        await expect(page.getByRole('heading', { name: 'Open a registration window' })).toBeVisible();
        await expectAccessible(page);
        await signOut(page);
    });
});
