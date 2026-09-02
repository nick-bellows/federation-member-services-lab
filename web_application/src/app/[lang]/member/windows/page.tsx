import { fetchFederationIdentity } from '@/lib/federation/api';
import { requireFederationClient } from '@/lib/federation/client';
import { listReferenceData, listWindows } from '@/lib/federation/queries';
import { getOidcAccessToken } from '@/lib/federation/session';
import createTranslation from 'next-translate/createTranslation';
import OpenWindowForm from './OpenWindowForm';

interface PageProps {
    params: { lang: string };
}

/**
 * Federation (fork): registration windows, and the form to open one for an
 * organization the signed-in administrator manages. The list is reference
 * data every member may read; the form is offered only for administered bodies.
 */
export default async function WindowsPage({ params }: PageProps) {
    const { t } = createTranslation('federation');
    const client = await requireFederationClient(params.lang);
    const token = await getOidcAccessToken();
    const [windows, reference, identity] = await Promise.all([
        listWindows(client),
        listReferenceData(client),
        token
            ? fetchFederationIdentity(token).catch(() => null)
            : Promise.resolve(null),
    ]);

    const administersFederation =
        (identity?.administeredFederations.length ?? 0) > 0;
    const administeredIds = new Set(
        identity?.administeredMemberOrganizations.map((o) => o.id) ?? [],
    );
    const manageable = reference.organizations.filter(
        (o) => administersFederation || administeredIds.has(o.id),
    );

    return (
        <section aria-labelledby="windows-heading">
            <h1 id="windows-heading" className="text-2xl font-bold">
                {t('windows.heading')}
            </h1>

            {windows.length === 0 ? (
                <p className="mt-6 text-slate-700">{t('windows.none')}</p>
            ) : (
                <div className="mt-6 overflow-x-auto">
                    <table className="w-full border-collapse text-left text-sm">
                        <caption className="sr-only">
                            {t('windows.table_caption')}
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
                                    {t('windows.opens')}
                                </th>
                                <th
                                    scope="col"
                                    className="py-2 pr-4 font-semibold"
                                >
                                    {t('windows.closes')}
                                </th>
                                <th
                                    scope="col"
                                    className="py-2 pr-4 font-semibold"
                                >
                                    {t('windows.roles')}
                                </th>
                                <th scope="col" className="py-2 font-semibold">
                                    {t('windows.state')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {windows.map((w) => (
                                <tr
                                    key={w.id}
                                    className="border-b border-slate-200"
                                >
                                    <td className="py-2 pr-4">
                                        {w.organization?.name ?? '—'}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {w.season?.label ?? '—'}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {new Date(
                                            w.opensAt,
                                        ).toLocaleDateString()}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {new Date(
                                            w.closesAt,
                                        ).toLocaleDateString()}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {w.roles
                                            .map((r) => t(`roles.${r}`))
                                            .join(', ')}
                                    </td>
                                    <td className="py-2">
                                        {w.isOpen
                                            ? t('windows.open')
                                            : t('windows.closed')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {manageable.length > 0 && (
                <section
                    aria-labelledby="open-window-heading"
                    className="mt-10"
                >
                    <h2
                        id="open-window-heading"
                        className="text-lg font-semibold"
                    >
                        {t('windows.open_heading')}
                    </h2>
                    <OpenWindowForm
                        lang={params.lang}
                        organizations={manageable}
                        seasons={reference.seasons}
                    />
                </section>
            )}
        </section>
    );
}
