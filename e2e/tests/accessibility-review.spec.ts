import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { appendFileSync, mkdirSync } from 'node:fs';

/**
 * The manual-review scaffolding for B6 (docs/ACCESSIBILITY.md): a keyboard-only
 * walk of every page of the slice recording the focus order and whether each
 * focused control shows a visible focus indicator, a best-practice axe scan on
 * top of the WCAG tags the journeys already use, and a slow-3G load of the
 * new-application page timed to a usable form. The walk runs in CI; the
 * throttled timing is machine-specific and is skipped there.
 */
const runId = Date.now().toString(36);
const applicant = { subject: `mock|a11y-${runId}`, email: `a11y-${runId}@northgate.example`, name: `A11y Reviewer ${runId}` };
const naslAdmin = { subject: 'mock|nasl-admin', email: 'nasl-admin@northgate.example', name: 'NASL Admin' };
const reportPath = 'docs/baseline/a11y_review_2026-09-03.txt';

function note(line: string) {
    mkdirSync('../docs/baseline', { recursive: true });
    appendFileSync(`../${reportPath}`, line + '\n');
}

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

/**
 * Tab through the page and record, for each stop, the element, its accessible
 * name and whether the focus indicator is visible (an outline or box-shadow
 * that differs from the unfocused state). Stops after a full cycle or 60 stops.
 */
async function keyboardWalk(page: Page, label: string) {
    const stops: string[] = [];
    let invisible = 0;
    await page.locator('body').focus();
    for (let i = 0; i < 60; i++) {
        await page.keyboard.press('Tab');
        const info = await page.evaluate(() => {
            const el = document.activeElement as HTMLElement | null;
            if (!el || el === document.body) return null;
            const style = getComputedStyle(el);
            const name = el.getAttribute('aria-label') || el.textContent?.trim().slice(0, 40) || el.getAttribute('name') || '';
            const focusVisible = el.matches(':focus-visible');
            const hasIndicator = focusVisible && (style.outlineStyle !== 'none' || style.boxShadow !== 'none');
            return { tag: el.tagName.toLowerCase(), name, hasIndicator, id: el.id };
        });
        if (!info) break;
        const key = `${info.tag}#${info.id}:${info.name}`;
        if (stops.includes(key)) break;
        stops.push(key);
        if (!info.hasIndicator) invisible++;
    }
    note(`[${label}] focus stops=${stops.length} without visible indicator=${invisible}`);
    for (const s of stops) note(`  ${s}`);
    return { stops, invisible };
}

async function bestPracticeScan(page: Page, label: string) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'])
        .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    const other = results.violations.filter((v) => v.impact !== 'serious' && v.impact !== 'critical');
    note(`[${label}] axe: serious/critical=${serious.length} minor/moderate=${other.map((v) => v.id).join(',') || 'none'}`);
    expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
}

test.describe.serial('accessibility review of the slice', () => {
    test('a member walks every page with the keyboard', async ({ page }) => {
        note(`# Accessibility review scaffolding, ${new Date().toISOString()} (run ${runId})`);
        await page.goto('/en/member/sign-in');
        await keyboardWalk(page, 'sign-in');
        await bestPracticeScan(page, 'sign-in');

        await signIn(page, applicant);
        for (const path of ['/en/member', '/en/member/applications', '/en/member/applications/new']) {
            await page.goto(path);
            const walk = await keyboardWalk(page, path);
            expect(walk.stops.length, `${path} is reachable by keyboard`).toBeGreaterThan(0);
            expect(walk.invisible, `${path}: every focused control shows a focus indicator`).toBe(0);
            await bestPracticeScan(page, path);
        }
        await signOut(page);
    });

    test('a reviewer walks the queue and windows pages with the keyboard', async ({ page }) => {
        await signIn(page, naslAdmin);
        for (const path of ['/en/member/review', '/en/member/windows']) {
            await page.goto(path);
            const walk = await keyboardWalk(page, path);
            expect(walk.stops.length, `${path} is reachable by keyboard`).toBeGreaterThan(0);
            expect(walk.invisible, `${path}: every focused control shows a focus indicator`).toBe(0);
            await bestPracticeScan(page, path);
        }
        await signOut(page);
    });

    test('the new-application page is usable on a slow connection', async ({ page, browser }) => {
        test.skip(!!process.env.CI, 'machine-specific timing; recorded locally in docs/baseline');
        await signIn(page, applicant);
        const context = page.context();
        const cdp = await context.newCDPSession(page);
        await cdp.send('Network.enable');
        // Slow 3G: 400 kbit/s down, 400 ms latency.
        await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 400, downloadThroughput: 50 * 1024, uploadThroughput: 50 * 1024 });
        const started = Date.now();
        await page.goto('/en/member/applications/new');
        await expect(page.getByLabel('Organization and season')).toBeVisible({ timeout: 60_000 });
        const usableAfterMs = Date.now() - started;
        await page.waitForLoadState('load');
        const loadedAfterMs = Date.now() - started;
        const transferred = await page.evaluate(() => performance.getEntriesByType('resource').reduce((sum, e) => sum + ((e as PerformanceResourceTiming).transferSize || 0), 0));
        note(`[slow-3g] new-application page: form usable after ${usableAfterMs} ms, load event after ${loadedAfterMs} ms, resources transferred ${Math.round(transferred / 1024)} KiB`);
        await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
        await browser.close;
    });
});
