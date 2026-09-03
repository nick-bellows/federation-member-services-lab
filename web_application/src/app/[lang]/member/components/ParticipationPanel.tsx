import { formatDateTime } from '@/lib/federation/format';
import type { Participation } from '@/lib/federation/types';
import createTranslation from 'next-translate/createTranslation';
import RefreshCredentialsButton from './RefreshCredentialsButton';

const classes: Record<Participation['status'], string> = {
    may_participate: 'bg-green-50 text-green-900 border-green-400',
    blocked: 'bg-red-50 text-red-900 border-red-400',
    unknown: 'bg-slate-100 text-slate-900 border-slate-400',
};

interface Props {
    lang: string;
    applicationId: string;
    participation: Participation;
    canRefresh: boolean;
}

/**
 * The derived answer with its reasons and its age. The age is always shown:
 * a stale answer is still an answer, but the reader must know it is old.
 */
export default function ParticipationPanel({
    lang,
    applicationId,
    participation,
    canRefresh,
}: Props) {
    const { t } = createTranslation('federation');
    const { status, reasons, asOf, fetchedAt, stale } = participation;

    return (
        <section
            aria-labelledby="participation-heading"
            className="mt-8 rounded border border-slate-300 p-4"
            data-participation={status}
        >
            <h2 id="participation-heading" className="text-lg font-semibold">
                {t('participation.heading')}
            </h2>
            <p className="mt-2 flex flex-wrap items-center gap-2">
                <span
                    className={`inline-block rounded border px-2 py-0.5 text-sm font-medium ${classes[status]}`}
                >
                    {t(`participation.statuses.${status}`)}
                </span>
                {stale && (
                    <span className="inline-block rounded border border-amber-400 bg-amber-50 px-2 py-0.5 text-sm font-medium text-amber-900">
                        {t('participation.stale')}
                    </span>
                )}
            </p>
            {reasons.length > 0 && (
                <ul className="mt-2 list-disc pl-5 text-sm">
                    {reasons.map((reason) => (
                        <li key={reason}>
                            {t(`participation.reasons.${reason}`)}
                        </li>
                    ))}
                </ul>
            )}
            <p className="mt-2 text-sm text-slate-700">
                {fetchedAt
                    ? t('participation.fetched_at', {
                          fetched: formatDateTime(fetchedAt, lang),
                          asOf: asOf ? formatDateTime(asOf, lang) : '—',
                      })
                    : t('participation.never_fetched')}
            </p>
            {canRefresh && (
                <RefreshCredentialsButton
                    lang={lang}
                    applicationId={applicationId}
                />
            )}
        </section>
    );
}
