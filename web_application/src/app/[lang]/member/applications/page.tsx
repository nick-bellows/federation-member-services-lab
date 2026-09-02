import { requireFederationClient } from '@/lib/federation/client';
import { listApplications } from '@/lib/federation/queries';
import createTranslation from 'next-translate/createTranslation';
import Link from 'next/link';
import { ApplicationStatusBadge } from '../components/StatusBadge';

interface PageProps {
    params: { lang: string };
}

/**
 * Federation (fork): the signed-in person's applications. The API returns
 * only what this user may see; reviewers land here too and see their own.
 */
export default async function ApplicationsPage({ params }: PageProps) {
    const { t } = createTranslation('federation');
    const client = await requireFederationClient(params.lang);
    const applications = await listApplications(client);
    const base = `/${params.lang}/member`;

    return (
        <section aria-labelledby="applications-heading">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <h1 id="applications-heading" className="text-2xl font-bold">
                    {t('applications.heading')}
                </h1>
                <Link
                    href={`${base}/applications/new`}
                    className="rounded border border-slate-900 bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700 focus:outline focus:outline-2 focus:outline-offset-2"
                >
                    {t('applications.start')}
                </Link>
            </div>

            {applications.length === 0 ? (
                <p className="mt-6 text-slate-700">{t('applications.none')}</p>
            ) : (
                <div className="mt-6 overflow-x-auto">
                    <table className="w-full border-collapse text-left text-sm">
                        <caption className="sr-only">
                            {t('applications.table_caption')}
                        </caption>
                        <thead>
                            <tr className="border-b border-slate-300">
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
                                    {t('fields.season')}
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
                                <th scope="col" className="py-2 font-semibold">
                                    <span className="sr-only">
                                        {t('applications.open')}
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {applications.map((application) => (
                                <tr
                                    key={application.id}
                                    className="border-b border-slate-200"
                                >
                                    <td className="py-2 pr-4">
                                        {application.organization?.name ?? '—'}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {application.season?.label ?? '—'}
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
                                    <td className="py-2">
                                        <Link
                                            href={`${base}/applications/${application.id}`}
                                            className="underline underline-offset-4 focus:outline focus:outline-2 focus:outline-offset-2"
                                        >
                                            {t('applications.open')}
                                            <span className="sr-only">
                                                {' '}
                                                {
                                                    application.organization
                                                        ?.code
                                                }{' '}
                                                {application.season?.label}
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
