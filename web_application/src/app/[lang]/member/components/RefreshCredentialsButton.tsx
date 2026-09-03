'use client';

import {
    refreshCredentials,
    type ActionResult,
} from '@/actions/federation/actions';
import useTranslation from 'next-translate/useTranslation';
import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';
import ActionMessage from './ActionMessage';

interface Props {
    lang: string;
    applicationId: string;
}

export default function RefreshCredentialsButton({
    lang,
    applicationId,
}: Props) {
    const { t } = useTranslation('federation');
    const router = useRouter();
    const [pending, startTransition] = useTransition();
    const [result, setResult] = useState<ActionResult | null>(null);

    function refresh() {
        setResult(null);
        startTransition(async () => {
            const outcome = await refreshCredentials({ applicationId, lang });
            setResult(outcome);
            if (outcome.ok) {
                router.refresh();
            }
        });
    }

    return (
        <div className="mt-3">
            <button
                type="button"
                onClick={refresh}
                disabled={pending}
                aria-busy={pending}
                aria-describedby={result ? 'participation-message' : undefined}
                className="rounded border border-slate-900 px-3 py-1.5 text-sm font-medium hover:bg-slate-100 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
            >
                {pending ? t('common.working') : t('participation.refresh')}
            </button>
            <ActionMessage id="participation-message" result={result} />
        </div>
    );
}
