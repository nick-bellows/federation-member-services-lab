import { fetchFederationIdentity } from '@/lib/federation/api';
import { getOidcAccessToken } from '@/lib/federation/session';
import createTranslation from 'next-translate/createTranslation';
import Link from 'next/link';
import { PropsWithChildren } from 'react';

interface MemberLayoutProps extends PropsWithChildren {
    params: { lang: string };
}

/**
 * Federation (fork): plain, accessible shell for the member pages. A skip
 * link, one header landmark with navigation by capability, one main landmark.
 * The identity is read on the server; a failed lookup simply hides the
 * capability links and lets the page decide what to do.
 */
export default async function MemberLayout({
    children,
    params,
}: MemberLayoutProps) {
    const { t } = createTranslation('federation');
    const token = await getOidcAccessToken();
    let scopes: string[] = [];

    if (token) {
        try {
            scopes = (await fetchFederationIdentity(token)).scopes;
        } catch {
            scopes = [];
        }
    }

    const base = `/${params.lang}/member`;
    const links: { href: string; label: string }[] = [];

    if (token) {
        links.push({ href: base, label: t('nav.home') });
        links.push({
            href: `${base}/applications`,
            label: t('nav.applications'),
        });

        if (scopes.includes('application:review')) {
            links.push({ href: `${base}/review`, label: t('nav.review') });
        }

        if (scopes.includes('organization:manage')) {
            links.push({ href: `${base}/windows`, label: t('nav.windows') });
        }
    }

    return (
        <div className="flex min-h-screen flex-col bg-white text-slate-900">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:outline focus:outline-2"
            >
                {t('nav.skip')}
            </a>
            <header className="border-b border-slate-200 px-6 py-4">
                <div className="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-4">
                    {/* slate-700 (#5b656a in upstream's theme) clears WCAG AA on white; slate-600 does not. */}
                    <p className="text-sm font-semibold tracking-wide text-slate-700 uppercase">
                        Northgate Soccer Federation
                    </p>
                    {links.length > 0 && (
                        <nav aria-label={t('nav.label')}>
                            <ul className="flex flex-wrap gap-4 text-sm">
                                {links.map((link) => (
                                    <li key={link.href}>
                                        <Link
                                            href={link.href}
                                            className="underline-offset-4 hover:underline focus:outline focus:outline-2 focus:outline-offset-2"
                                        >
                                            {link.label}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </nav>
                    )}
                </div>
            </header>
            <main
                id="main"
                className="mx-auto w-full max-w-4xl flex-1 px-6 py-10"
            >
                {children}
            </main>
        </div>
    );
}
