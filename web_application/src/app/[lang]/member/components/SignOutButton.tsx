'use client';

import { signOut } from 'next-auth/react';
import useTranslation from 'next-translate/useTranslation';

export default function SignOutButton({
    callbackUrl,
}: {
    callbackUrl: string;
}) {
    const { t } = useTranslation('federation');

    return (
        <button
            type="button"
            onClick={() => signOut({ callbackUrl })}
            className="rounded border border-slate-900 px-4 py-2 font-medium hover:bg-slate-100 focus:outline focus:outline-2 focus:outline-offset-2"
        >
            {t('sign_out')}
        </button>
    );
}
