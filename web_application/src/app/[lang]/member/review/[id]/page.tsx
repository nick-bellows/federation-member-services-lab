import { requireFederationClient } from '@/lib/federation/client';
import { getApplication } from '@/lib/federation/queries';
import { requiredDocumentsByRole } from '@/lib/federation/types';
import { memberMetadata } from '@/lib/federation/metadata';
import createTranslation from 'next-translate/createTranslation';
import { notFound } from 'next/navigation';
import HistoryList from '../../components/HistoryList';
import ParticipationPanel from '../../components/ParticipationPanel';
import { ApplicationStatusBadge } from '../../components/StatusBadge';
import DocumentReviewList from './DocumentReviewList';
import ReviewActions from './ReviewActions';

interface PageProps {
    params: { lang: string; id: string };
}

/**
 * Federation (fork): one application as a reviewer sees it. The API decides
 * who may see and decide; a 403 becomes "not found" here so the page never
 * confirms that an application exists to someone who may not see it.
 */
export function generateMetadata({ params }: PageProps) {
    return memberMetadata(params.lang, 'review_application');
}

export default async function ReviewApplicationPage({ params }: PageProps) {
    const { t } = createTranslation('federation');
    const client = await requireFederationClient(params.lang);
    const { view } = await getApplication(client, params.id);

    if (!view) {
        notFound();
    }

    const { attributes } = view;
    const required = requiredDocumentsByRole[attributes.role];

    return (
        <article aria-labelledby="review-heading">
            <h1 id="review-heading" className="text-2xl font-bold">
                {t('review.application_heading', {
                    name: view.applicant?.name ?? '',
                })}
            </h1>
            <p className="mt-1 text-slate-700">
                {t(`roles.${attributes.role}`)} · {view.organization?.name} ·{' '}
                {view.season?.label}
            </p>

            <section
                aria-labelledby="review-status-heading"
                className="mt-8 rounded border border-slate-300 p-4"
            >
                <h2
                    id="review-status-heading"
                    className="text-lg font-semibold"
                >
                    {t('fields.status')}
                </h2>
                <p className="mt-2">
                    <ApplicationStatusBadge
                        status={attributes.status}
                        label={t(`statuses.${attributes.status}`)}
                    />
                </p>
                {attributes.statusReason && (
                    <p className="mt-2">
                        <span className="font-medium">
                            {t('application.reason')}:
                        </span>{' '}
                        {attributes.statusReason}
                    </p>
                )}
            </section>

            <ParticipationPanel
                lang={params.lang}
                applicationId={view.id}
                participation={attributes.participation}
                canRefresh
            />

            <section aria-labelledby="applicant-heading" className="mt-8">
                <h2 id="applicant-heading" className="text-lg font-semibold">
                    {t('review.applicant')}
                </h2>
                <dl className="mt-3 grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-[max-content_1fr]">
                    <dt className="font-medium">{t('fields.name')}</dt>
                    <dd>{view.applicant?.name ?? '—'}</dd>
                    <dt className="font-medium">{t('me.email')}</dt>
                    <dd>{view.applicant?.email ?? '—'}</dd>
                    <dt className="font-medium">{t('fields.date_of_birth')}</dt>
                    <dd>{attributes.dateOfBirth ?? '—'}</dd>
                    <dt className="font-medium">{t('fields.phone')}</dt>
                    <dd>{attributes.phone ?? '—'}</dd>
                    <dt className="font-medium">{t('fields.notes')}</dt>
                    <dd className="whitespace-pre-wrap">
                        {attributes.applicantNotes ?? '—'}
                    </dd>
                </dl>
            </section>

            <section
                aria-labelledby="review-documents-heading"
                className="mt-8"
            >
                <h2
                    id="review-documents-heading"
                    className="text-lg font-semibold"
                >
                    {t('application.documents')}
                </h2>
                <DocumentReviewList
                    lang={params.lang}
                    applicationId={view.id}
                    reviewable={attributes.status === 'under_review'}
                    required={required}
                    documents={view.documents.map((d) => ({
                        id: d.id,
                        ...d.attributes,
                    }))}
                />
            </section>

            <ReviewActions
                lang={params.lang}
                applicationId={view.id}
                status={attributes.status}
            />

            <section aria-labelledby="review-history-heading" className="mt-8">
                <h2
                    id="review-history-heading"
                    className="text-lg font-semibold"
                >
                    {t('application.history')}
                </h2>
                <HistoryList entries={attributes.history} lang={params.lang} />
            </section>
        </article>
    );
}
