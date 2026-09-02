'use client';

import { signIn } from 'next-auth/react';
import useTranslation from 'next-translate/useTranslation';
import { useState } from 'react';

interface SignInButtonsProps {
    providers: { id: string; label: string }[];
    callbackUrl: string;
}

export default function SignInButtons({
    providers,
    callbackUrl,
}: SignInButtonsProps) {
    const { t } = useTranslation('federation');
    const [pending, setPending] = useState<string | null>(null);

    return (
        <ul className="mt-6 space-y-3" aria-label={t('sign_in.providers')}>
            {providers.map((provider) => (
                <li key={provider.id}>
                    <button
                        type="button"
                        onClick={() => {
                            setPending(provider.id);
                            signIn(provider.id, { callbackUrl });
                        }}
                        disabled={pending !== null}
                        aria-busy={pending === provider.id}
                        className="w-full rounded border border-slate-900 px-4 py-3 text-left font-medium hover:bg-slate-100 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
                    >
                        {t('sign_in.with', { provider: provider.label })}
                    </button>
                </li>
            ))}
        </ul>
    );
}
