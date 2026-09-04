# Accessibility review of the registration slice

A manual WCAG 2.1 AA review of the seven member and reviewer pages (B6, ADR-0013), on top of the automated axe scan the browser journeys have run on every page since M3. Automated checks catch roughly a third of the problems; this document records what a person checked, with the evidence in `docs/baseline/a11y_review_2026-09-03.txt` and `e2e/tests/accessibility-review.spec.ts`.

Pages: sign-in, member home, applications list, new application, application detail, review queue, review detail, registration windows. Reviewed on 2026-09-03 against the running stack with a keyboard only and with the accessibility tree as a screen reader would receive it (Playwright's role and name queries).

## What holds, by criterion

| Criterion | Evidence |
|---|---|
| 1.1.1 Non-text content | The only images are the seeded screenshots in the README; the pages carry none. Status badges are text with colour as reinforcement (`StatusBadge`). |
| 1.3.1 Info and relationships | Every form control has a `<label for>`; help text and errors are linked with `aria-describedby`; tables have `<caption>` and header cells; headings are nested h1, h2 without skips (best-practice scan clean). |
| 1.3.2 Meaningful sequence | The keyboard walk visits controls in reading order on every page (`a11y_review` focus stops). |
| 1.4.3 Contrast | axe at AA on every journey page; upstream's `slate-600` (#8c9da6) failed in M3 and the pages use `slate-700` and darker (M3 learning log). |
| 2.1.1 Keyboard | The four journeys complete sign-in, start, documents, submit, review, approve and window creation; the walk finds every control reachable by Tab on all seven pages. |
| 2.1.2 No keyboard trap | The walk terminates by cycling back on every page; no focus trap. |
| 2.4.3 Focus order | Recorded per page in the baseline: sign-in 2 stops, home 4, applications 4, new application 6, review queue 5, windows 8, all in visual order. |
| 2.4.6 Headings and labels | One h1 per page naming the page; section headings name their region (`aria-labelledby` on sections). |
| 2.4.7 Focus visible | Every focused control shows an outline (`focus:outline` classes); the walk found zero stops without a visible indicator. |
| 3.1.1 Language of page | `<html lang>` follows the route locale (`en`, `de`); every string is translated in both. |
| 3.2.2 On input | Selecting an organization changes only the offered roles; no automatic submission. |
| 3.3.1 Error identification | Field errors are text next to the field, linked by `aria-describedby`, with `aria-invalid`; action failures render in an `alert` region. |
| 3.3.2 Labels or instructions | Labels on every control; help text under the organization select and the documents panel. |
| 3.3.3 Error suggestion | The incomplete-submission error lists what is missing (documents, date of birth). |
| 4.1.2 Name, role, value | Buttons carry `aria-busy` while pending; document-decision and transition buttons are named with the document type or the action. |
| 4.1.3 Status messages | Successes render in a `status` region, failures in an `alert` region (`ActionMessage`). |

## Findings

1. **Skip link.** A `Skip to content` link existed in the layout but its target, the `main` landmark, was not focusable, so activating it scrolled without moving focus. **Resolved in B9 (2026-09-04):** `main` carries `tabindex="-1"`; the review spec presses Tab, expects the skip link, presses Enter and expects `main#main` to hold focus.
2. **Page titles.** The browser tab title was the same for every member page. **Resolved in B9:** every member page exports `generateMetadata` through `memberMetadata`, which renders the page name, then the site, in the page's language (`titles` in the federation namespace); the review spec asserts each title.
3. **Transition buttons.** `Approve`, `Reject`, `Start review`, `Request information`, `Submit application` and `Withdraw application` were unambiguous in context but not out of it. **Resolved in B9:** each carries `aria-describedby` pointing at a visually hidden sentence saying what it does to the application, read before the person confirms; the review spec asserts the description exists and is a sentence.

No serious or critical issue was found in B6; the three findings were improvements, not failures against AA, and all three are in place as of B9 (`docs/baseline/a11y_review_2026-09-04.txt`). A screen reader run by ear remains undone.

## Low bandwidth

The new-application page under a slow-3G profile (400 ms latency, 400 kbit/s) from the **development server** became usable after 42.6 s with 2.06 MiB transferred. That number describes `next dev`, which ships unminified bundles and hot-reload code, not the product. The production build's first-load JavaScript for the member pages is 92 to 100 kB per page with 88.4 kB shared (`next build`, recorded in the same baseline file), which on the same profile is about two seconds for the scripts. The pages render their forms server-side, so the form is visible before the scripts arrive; the interactive parts (file hashing, action buttons) need them. A production measurement belongs to B8 once a production image serves the frontend.

## Not covered

A screen reader was not run by ear; the accessibility tree was read through Playwright's role and name queries, which is what the automated walk checks. Upstream's club-management and public apply pages were out of scope (roadmap decision at the start of B6).
