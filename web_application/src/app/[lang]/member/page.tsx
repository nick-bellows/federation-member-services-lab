import {
    FederationApiError,
    fetchFederationIdentity,
} from '@/lib/federation/api';
import { getOidcAccessToken } from '@/lib/federation/session';
import createTranslation from 'next-translate/createTranslation';
import { redirect } from 'next/navigation';
import SignOutButton from './components/SignOutButton';

interface MemberPageProps {
    params: { lang: string };
}

/**
 * Federation (fork): who the federation thinks you are. The page holds no
 * credentials: the access token is read from the encrypted cookie on the
 * server and sent to the API, which validates it and answers from the database.
 */
export default async function MemberPage({ params }: MemberPageProps) {
    const { t } = createTranslation('federation');
    const accessToken = await getOidcAccessToken();

    if (!accessToken) {
        redirect(`/${params.lang}/member/sign-in`);
    }

    let identity;

    try {
        identity = await fetchFederationIdentity(accessToken);
    } catch (error) {
        if (error instanceof FederationApiError && error.status === 401) {
            // The API rejected the token (expired, wrong audience, unknown
            // identity that could not be provisioned). Start over.
            redirect(`/${params.lang}/member/sign-in`);
        }

        throw error;
    }

    return (
        <article aria-labelledby="member-heading">
            <div className="flex items-start justify-between gap-4">
                <h1 id="member-heading" className="text-2xl font-bold">
                    {t('me.heading', { name: identity.name })}
                </h1>
                <SignOutButton callbackUrl={`/${params.lang}/member/sign-in`} />
            </div>

            <section aria-labelledby="identity-heading" className="mt-8">
                <h2 id="identity-heading" className="text-lg font-semibold">
                    {t('me.identity')}
                </h2>
                <dl className="mt-3 grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-[max-content_1fr]">
                    <dt className="font-medium">{t('me.email')}</dt>
                    <dd>{identity.email}</dd>
                    <dt className="font-medium">{t('me.issuer')}</dt>
                    <dd className="break-all">{identity.issuer}</dd>
                    <dt className="font-medium">{t('me.subject')}</dt>
                    <dd className="break-all">{identity.subject}</dd>
                </dl>
            </section>

            <section aria-labelledby="scopes-heading" className="mt-8">
                <h2 id="scopes-heading" className="text-lg font-semibold">
                    {t('me.scopes')}
                </h2>
                <p className="mt-1 text-sm text-slate-700">
                    {t('me.scopes_help')}
                </p>
                <ul className="mt-3 list-disc pl-6">
                    {identity.scopes.map((scope) => (
                        <li key={scope}>
                            <code>{scope}</code>
                        </li>
                    ))}
                </ul>
            </section>

            <section aria-labelledby="bodies-heading" className="mt-8">
                <h2 id="bodies-heading" className="text-lg font-semibold">
                    {t('me.administered')}
                </h2>
                {identity.administeredFederations.length === 0 &&
                identity.administeredMemberOrganizations.length === 0 ? (
                    <p className="mt-3">{t('me.administered_none')}</p>
                ) : (
                    <ul className="mt-3 list-disc pl-6">
                        {identity.administeredFederations.map((federation) => (
                            <li key={`f-${federation.id}`}>
                                {federation.name} ({federation.code})
                            </li>
                        ))}
                        {identity.administeredMemberOrganizations.map(
                            (organization) => (
                                <li key={`o-${organization.id}`}>
                                    {organization.name} ({organization.code})
                                </li>
                            ),
                        )}
                    </ul>
                )}
            </section>
        </article>
    );
}
