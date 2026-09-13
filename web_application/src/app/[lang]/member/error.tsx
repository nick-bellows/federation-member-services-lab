'use client';

import useTranslation from 'next-translate/useTranslation';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useEffect } from 'react';

interface Props {
    error: Error & { digest?: string };
    reset: () => void;
}

/**
 * Federation (fork): the error boundary for the member pages. A list the API
 * refused, an unreachable API or a server action that threw lands here
 * instead of on the framework's default screen, with a way back: try again,
 * or sign in afresh when the session is the likely cause. In production the
 * server's message is not sent to the browser, so the text is generic.
 */
export default function MemberError({ error, reset }: Props) {
    const { t } = useTranslation('federation');
    const params = useParams<{ lang: string }>();
    const lang = params?.lang ?? 'en';

    useEffect(() => {
        console.error(error);
    }, [error]);

    return (
        <section aria-labelledby="member-error-heading" role="alert">
            <h1 id="member-error-heading" className="text-2xl font-bold">
                {t('errors.heading')}
            </h1>
            <p className="mt-2 text-slate-700">{t('errors.description')}</p>
            <div className="mt-6 flex flex-wrap gap-3">
                <button
                    type="button"
                    onClick={() => reset()}
                    className="rounded border border-slate-900 bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700 focus:outline focus:outline-2 focus:outline-offset-2"
                >
                    {t('errors.retry')}
                </button>
                <Link
                    href={`/${lang}/member/sign-in`}
                    className="rounded border border-slate-900 px-4 py-2 font-medium hover:bg-slate-100 focus:outline focus:outline-2 focus:outline-offset-2"
                >
                    {t('errors.sign_in_again')}
                </Link>
            </div>
        </section>
    );
}
