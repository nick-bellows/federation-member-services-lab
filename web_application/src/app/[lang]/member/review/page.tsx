import { formatDate } from '@/lib/federation/format';
import { requireFederationClient } from '@/lib/federation/client';
import { listApplications } from '@/lib/federation/queries';
import { memberMetadata } from '@/lib/federation/metadata';
import createTranslation from 'next-translate/createTranslation';
import Link from 'next/link';
import { ApplicationStatusBadge } from '../components/StatusBadge';

interface PageProps {
    params: { lang: string };
}

/**
 * Federation (fork): the review queue. The API scopes the list to the
 * organizations the signed-in reviewer administers; a non-reviewer sees only
 * their own applications here, which is harmless and honest.
 */
export function generateMetadata({ params }: PageProps) {
    return memberMetadata(params.lang, 'review');
}

export default async function ReviewQueuePage({ params }: PageProps) {
    const { t } = createTranslation('federation');
    const client = await requireFederationClient(params.lang);
    const queue = await listApplications(client, [
        'submitted',
        'under_review',
        'needs_information',
    ]);
    const base = `/${params.lang}/member/review`;

    return (
        <section aria-labelledby="queue-heading">
            <h1 id="queue-heading" className="text-2xl font-bold">
                {t('review.heading')}
            </h1>
            <p className="mt-2 text-slate-700">{t('review.intro')}</p>

            {queue.length === 0 ? (
                <p className="mt-6 text-slate-700">{t('review.empty')}</p>
            ) : (
                <div className="mt-6 overflow-x-auto">
                    <table className="w-full border-collapse text-left text-sm">
                        <caption className="sr-only">
                            {t('review.table_caption')}
                        </caption>
                        <thead>
                            <tr className="border-b border-slate-300">
                                <th
                                    scope="col"
                                    className="py-2 pr-4 font-semibold"
                                >
                                    {t('fields.applicant')}
                                </th>
                                <th
                                    scope="col"
                                    className="py-2 pr-4 font-semibold"
                                >
                                    {t('fields.organization')}
                                </th>
                                <th
                                    scope="col"
                                    className="py-2 pr-4 font-semibold"
                                >
                                    {t('fields.role')}
                                </th>
                                <th
                                    scope="col"
                                    className="py-2 pr-4 font-semibold"
                                >
                                    {t('fields.status')}
                                </th>
                                <th
                                    scope="col"
                                    className="py-2 pr-4 font-semibold"
                                >
                                    {t('fields.submitted_at')}
                                </th>
                                <th scope="col" className="py-2 font-semibold">
                                    <span className="sr-only">
                                        {t('review.open')}
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {queue.map((application) => (
                                <tr
                                    key={application.id}
                                    className="border-b border-slate-200"
                                >
                                    <td className="py-2 pr-4">
                                        {application.applicant?.name ?? '—'}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {application.organization?.code ?? '—'}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {t(
                                            `roles.${application.attributes.role}`,
                                        )}
                                    </td>
                                    <td className="py-2 pr-4">
                                        <ApplicationStatusBadge
                                            status={
                                                application.attributes.status
                                            }
                                            label={t(
                                                `statuses.${application.attributes.status}`,
                                            )}
                                        />
                                    </td>
                                    <td className="py-2 pr-4">
                                        {application.attributes.submittedAt
                                            ? formatDate(
                                                  application.attributes
                                                      .submittedAt,
                                                  params.lang,
                                              )
                                            : '—'}
                                    </td>
                                    <td className="py-2">
                                        <Link
                                            href={`${base}/${application.id}`}
                                            className="underline underline-offset-4 focus:outline focus:outline-2 focus:outline-offset-2"
                                        >
                                            {t('review.open')}
                                            <span className="sr-only">
                                                {' '}
                                                {application.applicant?.name}
                                            </span>
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
