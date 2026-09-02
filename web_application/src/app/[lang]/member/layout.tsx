import { PropsWithChildren } from 'react';

/**
 * Federation (fork): plain, accessible shell for the member pages. A skip
 * link, one header landmark, one main landmark.
 */
export default function MemberLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col bg-white text-slate-900">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:outline focus:outline-2"
            >
                Skip to content
            </a>
            <header className="border-b border-slate-200 px-6 py-4">
                {/* slate-700 (#5b656a in upstream's theme) clears WCAG AA on white; slate-600 does not. */}
                <p className="text-sm font-semibold tracking-wide text-slate-700 uppercase">
                    Northgate Soccer Federation
                </p>
            </header>
            <main
                id="main"
                className="mx-auto w-full max-w-3xl flex-1 px-6 py-10"
            >
                {children}
            </main>
        </div>
    );
}
