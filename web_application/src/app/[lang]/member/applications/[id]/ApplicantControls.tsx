'use client';

import {
    transitionApplication,
    type ActionResult,
} from '@/actions/federation/actions';
import type { ApplicationStatus } from '@/lib/federation/types';
import useTranslation from 'next-translate/useTranslation';
import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';
import ActionMessage from '../../components/ActionMessage';

interface Props {
    lang: string;
    applicationId: string;
    status: ApplicationStatus;
    complete: boolean;
}

/**
 * Submit and cancel. One idempotency key per attempt: a double click or a
 * retried request reuses it, so the API answers the same state once.
 */
export default function ApplicantControls({
    lang,
    applicationId,
    status,
    complete,
}: Props) {
    const { t } = useTranslation('federation');
    const router = useRouter();
    const [pending, startTransition] = useTransition();
    const [attemptKey, setAttemptKey] = useState(
        () => `web-${crypto.randomUUID()}`,
    );
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [result, setResult] = useState<ActionResult | null>(null);

    const canSubmit = status === 'draft' || status === 'needs_information';
    const canCancel =
        status === 'draft' ||
        status === 'submitted' ||
        status === 'needs_information';

    if (!canSubmit && !canCancel) {
        return null;
    }

    function run(action: 'submit' | 'cancel') {
        setResult(null);

        startTransition(async () => {
            const outcome = await transitionApplication({
                applicationId,
                action,
                attemptKey,
                lang,
            });
            setResult(outcome);

            if (outcome.ok) {
                setAttemptKey(`web-${crypto.randomUUID()}`);
                setConfirmCancel(false);
                router.refresh();
            }
        });
    }

    return (
        <section
            aria-labelledby="actions-heading"
            className="mt-8 rounded border border-slate-300 p-4"
        >
            <h2 id="actions-heading" className="text-lg font-semibold">
                {t('application.actions')}
            </h2>
            {canSubmit && !complete && (
                <p className="mt-2 text-sm text-slate-700">
                    {t('application.incomplete_help')}
                </p>
            )}
            <div className="mt-3 flex flex-wrap gap-3">
                {canSubmit && (
                    <button
                        type="button"
                        onClick={() => run('submit')}
                        disabled={pending}
                        aria-busy={pending}
                        className="rounded border border-slate-900 bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
                    >
                        {status === 'needs_information'
                            ? t('application.resubmit')
                            : t('application.submit')}
                    </button>
                )}
                {canCancel && !confirmCancel && (
                    <button
                        type="button"
                        onClick={() => setConfirmCancel(true)}
                        disabled={pending}
                        className="rounded border border-slate-900 px-4 py-2 font-medium hover:bg-slate-100 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
                    >
                        {t('application.cancel')}
                    </button>
                )}
                {canCancel && confirmCancel && (
                    <>
                        <button
                            type="button"
                            onClick={() => run('cancel')}
                            disabled={pending}
                            aria-busy={pending}
                            className="rounded border border-red-700 bg-red-700 px-4 py-2 font-medium text-white hover:bg-red-800 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
                        >
                            {t('application.cancel_confirm')}
                        </button>
                        <button
                            type="button"
                            onClick={() => setConfirmCancel(false)}
                            disabled={pending}
                            className="rounded border border-slate-900 px-4 py-2 font-medium hover:bg-slate-100 focus:outline focus:outline-2 focus:outline-offset-2"
                        >
                            {t('common.keep')}
                        </button>
                    </>
                )}
            </div>
            <ActionMessage
                result={result}
                successText={
                    result?.status
                        ? t(`status_explanations.${result.status}`)
                        : undefined
                }
            />
        </section>
    );
}
