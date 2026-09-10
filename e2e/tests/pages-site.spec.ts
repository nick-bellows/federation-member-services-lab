import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * The documentation site on GitHub Pages (docs/, jekyll-theme-minimal): the
 * landing page, the case study and the threat model must answer, pass axe on
 * WCAG 2.1 AA, and link only to things that resolve (the baseline records,
 * the decision records, the demo). Runs only when PAGES_URL is set: the site
 * exists after a merge to main, not in a pull request's CI.
 *
 *   PAGES_URL=https://nick-bellows.github.io/federation-member-services-lab npx playwright test tests/pages-site.spec.ts
 */
const base = process.env.PAGES_URL;
test.skip(!base, 'PAGES_URL is not set; the documentation site is checked after a merge, not in CI');

const pages = [
    { path: '/', name: 'landing page', expects: 'Federation Member Services Lab' },
    { path: '/CASE_STUDY', name: 'case study', expects: 'Numbers, with their caveats' },
    { path: '/THREAT_MODEL', name: 'threat model', expects: 'Tree 1' },
];

test('the documentation site answers, is accessible and its links resolve', async ({ page, request }) => {
    const findings: string[] = [];

    for (const page_ of pages) {
        const response = await page.goto(`${base}${page_.path}`);
        expect(response?.status(), page_.path).toBe(200);
        await expect(page.getByText(page_.expects).first()).toBeVisible();

        const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
        for (const violation of results.violations) {
            findings.push(`${page_.path}: axe ${violation.id} (${violation.impact}) at ${violation.nodes.slice(0, 3).map((n) => n.target.join(' ')).join('; ')}`);
        }

        // Every same-site link the page makes resolves.
        const siteRoot = new URL(base).origin + new URL(base).pathname.replace(/\/$/, '');
        const hrefs = await page.locator('a[href]').evaluateAll((anchors) => anchors.map((a) => (a as HTMLAnchorElement).href));
        const unique = [...new Set(hrefs.filter((h) => h.startsWith(siteRoot)).map((h) => h.split('#')[0]))];
        for (const href of unique) {
            const head = await request.head(href).catch(() => null);
            const status = head?.status() ?? 0;
            if (status < 200 || status >= 400) {
                findings.push(`${page_.path}: link ${href} answered ${status}`);
            }
        }
        console.log(`${page_.name}: ${results.violations.length} axe violation(s), ${unique.length} same-site link(s) checked`);
    }

    expect(findings, findings.join('\n')).toEqual([]);
});
