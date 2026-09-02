import { requireFederationClient } from '@/lib/federation/client';
import { getApplication } from '@/lib/federation/queries';
import {
    editableStatuses,
    requiredDocumentsByRole,
} from '@/lib/federation/types';
import createTranslation from 'next-translate/createTranslation';
import { notFound } from 'next/navigation';
import HistoryList from '../../components/HistoryList';
import { ApplicationStatusBadge } from '../../components/StatusBadge';
import ApplicantControls from './ApplicantControls';
import DetailsForm from './DetailsForm';
import DocumentsPanel from './DocumentsPanel';

interface PageProps {
    params: { lang: string; id: string };
}

/**
 * Federation (fork): one application as its applicant sees it. What may be
 * changed depends on the status; the API is the authority, the page only
 * hides controls that would be refused anyway.
 */
export default async function ApplicationPage({ params }: PageProps) {
    const { t } = createTranslation('federation');
    const client = await requireFederationClient(params.lang);
    const { view } = await getApplication(client, params.id);

    if (!view) {
        notFound();
    }

    const { attributes } = view;
    const editable = editableStatuses.includes(attributes.status);
    const required = requiredDocumentsByRole[attributes.role];
    const complete =
        attributes.missingRequiredDocuments.length === 0 &&
        attributes.dateOfBirth !== null;

    return (
        <article aria-labelledby="application-heading">
            <h1 id="application-heading" className="text-2xl font-bold">
                {t('application.heading', {
                    role: t(`roles.${attributes.role}`),
                    organization: view.organization?.name ?? '',
                })}
            </h1>
            <p className="mt-1 text-slate-700">
                {view.season?.label} ·{' '}
                {t('application.started', {
                    date: new Date(attributes.createdAt).toLocaleDateString(),
                })}
            </p>

            <section
                aria-labelledby="status-heading"
                className="mt-8 rounded border border-slate-300 p-4"
            >
                <h2 id="status-heading" className="text-lg font-semibold">
                    {t('fields.status')}
                </h2>
                <p className="mt-2">
                    <ApplicationStatusBadge
                        status={attributes.status}
                        label={t(`statuses.${attributes.status}`)}
                    />
                </p>
                <p className="mt-2 text-slate-700">
                    {t(`status_explanations.${attributes.status}`)}
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

            <section aria-labelledby="details-heading" className="mt-8">
                <h2 id="details-heading" className="text-lg font-semibold">
                    {t('application.details')}
                </h2>
                <DetailsForm
                    lang={params.lang}
                    applicationId={view.id}
                    editable={editable}
                    dateOfBirth={attributes.dateOfBirth ?? ''}
                    phone={attributes.phone ?? ''}
                    applicantNotes={attributes.applicantNotes ?? ''}
                />
            </section>

            <section aria-labelledby="documents-heading" className="mt-8">
                <h2 id="documents-heading" className="text-lg font-semibold">
                    {t('application.documents')}
                </h2>
                <p className="mt-1 text-sm text-slate-700">
                    {t('application.documents_help')}
                </p>
                <DocumentsPanel
                    lang={params.lang}
                    applicationId={view.id}
                    editable={editable}
                    required={required}
                    documents={view.documents.map((d) => ({
                        id: d.id,
                        ...d.attributes,
                    }))}
                />
            </section>

            <ApplicantControls
                lang={params.lang}
                applicationId={view.id}
                status={attributes.status}
                complete={complete}
            />

            <section aria-labelledby="history-heading" className="mt-8">
                <h2 id="history-heading" className="text-lg font-semibold">
                    {t('application.history')}
                </h2>
                <HistoryList entries={attributes.history} />
            </section>
        </article>
    );
}
