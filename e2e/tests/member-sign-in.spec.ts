import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * The identity boundary, end to end in a real browser:
 *
 *   /en/member  → redirected to /en/member/sign-in (no session)
 *   "Continue with Northgate ID" → authorization-code flow with PKCE to the
 *   mock OIDC provider → its interactive login page (subject + claims JSON)
 *   → next-auth callback → /en/member renders what the Laravel API says about
 *   the signed-in user, from the database, using the bearer access token.
 *
 * The persona's e-mail matches a seeded user (NorthgateDemoSeeder), so the
 * first sign-in links the identity to that user rather than creating one.
 */
const persona = {
    subject: 'mock|alex',
    claims: {
        email: 'alex.participant@northgate.example',
        email_verified: true,
        name: 'Alex Participant',
    },
};

async function expectNoSeriousAccessibilityViolations(page: Parameters<typeof AxeBuilder>[0]['page']) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();

    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');

    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
}

test('an anonymous visitor is sent to the sign-in page', async ({ page }) => {
    await page.goto('/en/member');

    await expect(page).toHaveURL(/\/en\/member\/sign-in$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Sign in to Northgate');
    await expect(page.getByRole('button', { name: 'Continue with Northgate ID' })).toBeVisible();

    await expectNoSeriousAccessibilityViolations(page);
});

test('signing in through the OIDC provider shows the identity the API resolved', async ({ page }) => {
    await page.goto('/en/member/sign-in');
    await page.getByRole('button', { name: 'Continue with Northgate ID' }).click();

    // mock-oauth2-server's interactive login page.
    await expect(page).toHaveURL(/\/default\/authorize/);
    await page.locator('input[name="username"]').fill(persona.subject);
    await page.locator('textarea[name="claims"]').fill(JSON.stringify(persona.claims));
    await page.locator('input[type="submit"]').click();

    await expect(page).toHaveURL(/\/en\/member$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Hello, Alex Participant');
    await expect(page.getByText(persona.claims.email)).toBeVisible();
    await expect(page.getByText(persona.subject)).toBeVisible();

    const scopes = page.getByRole('region', { name: 'What you can do here' }).getByRole('listitem');
    await expect(scopes).toHaveText(['member:read:self', 'member:update:self', 'application:create']);

    await expectNoSeriousAccessibilityViolations(page);

    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/en\/member\/sign-in$/);
});

test('the API refuses an anonymous call to the identity endpoint', async ({ request }) => {
    // The member page relies on this refusal to send visitors back to sign-in.
    // Wrong issuer, audience, expiry and signature cases are covered in PHPUnit
    // (OidcTokenVerifierTest, OidcGuardTest) where tokens can be forged at will.
    const response = await request.get(`${process.env.API_URL ?? 'http://localhost:3001'}/api/v1/federation-identity/me`, {
        headers: { Accept: 'application/json' },
    });

    expect(response.status()).toBe(401);
});
