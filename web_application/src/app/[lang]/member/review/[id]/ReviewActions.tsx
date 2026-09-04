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
}

type ReviewAction =
    'start-review' | 'request-information' | 'approve' | 'reject';

/**
 * The reviewer's decisions. Reasons are typed once, in one field, and sent
 * with the action that needs them; the API refuses a rejection or an
 * information request without one.
 */
export default function ReviewActions({ lang, applicationId, status }: Props) {
    const { t } = useTranslation('federation');
    const router = useRouter();
    const [pending, startTransition] = useTransition();
    const [reason, setReason] = useState('');
    const [result, setResult] = useState<ActionResult | null>(null);
    const [attemptKey, setAttemptKey] = useState(
        () => `web-${crypto.randomUUID()}`,
    );

    const available: ReviewAction[] =
        status === 'submitted'
            ? ['start-review']
            : status === 'under_review'
              ? ['request-information', 'approve', 'reject']
              : [];

    if (available.length === 0) {
        return null;
    }

    function run(action: ReviewAction) {
        setResult(null);

        startTransition(async () => {
            const outcome = await transitionApplication({
                applicationId,
                action,
                reason,
                attemptKey,
                lang,
            });
            setResult(outcome);

            if (outcome.ok) {
                setAttemptKey(`web-${crypto.randomUUID()}`);
                setReason('');
                router.refresh();
            }
        });
    }

    const needsReason = available.includes('request-information');

    return (
        <section
            aria-labelledby="decision-heading"
            className="mt-8 rounded border border-slate-300 p-4"
        >
            <h2 id="decision-heading" className="text-lg font-semibold">
                {t('review.decision')}
            </h2>
            {needsReason && (
                <div className="mt-3">
                    <label
                        htmlFor="decision-reason"
                        className="block font-medium"
                    >
                        {t('review.reason')}
                    </label>
                    <textarea
                        id="decision-reason"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        rows={3}
                        maxLength={2000}
                        aria-describedby="decision-reason-help"
                        className="mt-1 w-full rounded border border-slate-500 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2"
                    />
                    <p
                        id="decision-reason-help"
                        className="mt-1 text-sm text-slate-700"
                    >
                        {t('review.reason_help')}
                    </p>
                </div>
            )}
            {/* Each decision button is described by what it does to the
                application, read before the person confirms (B9, ACCESSIBILITY.md). */}
            <div className="mt-3 flex flex-wrap gap-3">
                {available.includes('start-review') && (
                    <button
                        type="button"
                        onClick={() => run('start-review')}
                        disabled={pending}
                        aria-busy={pending}
                        aria-describedby="decision-start-review-help"
                        className="rounded border border-slate-900 bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
                    >
                        {t('review.start')}
                    </button>
                )}
                {available.includes('approve') && (
                    <button
                        type="button"
                        onClick={() => run('approve')}
                        disabled={pending}
                        aria-busy={pending}
                        aria-describedby="decision-approve-help"
                        className="rounded border border-green-800 bg-green-800 px-4 py-2 font-medium text-white hover:bg-green-900 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
                    >
                        {t('review.approve')}
                    </button>
                )}
                {available.includes('request-information') && (
                    <button
                        type="button"
                        onClick={() => run('request-information')}
                        disabled={pending}
                        aria-busy={pending}
                        aria-describedby="decision-request-information-help"
                        className="rounded border border-slate-900 px-4 py-2 font-medium hover:bg-slate-100 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
                    >
                        {t('review.request_information')}
                    </button>
                )}
                {available.includes('reject') && (
                    <button
                        type="button"
                        onClick={() => run('reject')}
                        disabled={pending}
                        aria-busy={pending}
                        aria-describedby="decision-reject-help"
                        className="rounded border border-red-700 px-4 py-2 font-medium text-red-900 hover:bg-red-50 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
                    >
                        {t('review.reject')}
                    </button>
                )}
            </div>
            <div className="sr-only">
                <p id="decision-start-review-help">{t('review.start_help')}</p>
                <p id="decision-approve-help">{t('review.approve_help')}</p>
                <p id="decision-request-information-help">
                    {t('review.request_information_help')}
                </p>
                <p id="decision-reject-help">{t('review.reject_help')}</p>
            </div>
            <ActionMessage
                result={result}
                successText={
                    result?.status ? t(`statuses.${result.status}`) : undefined
                }
            />
        </section>
    );
}
