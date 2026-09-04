import { configuredFederationProviders } from '@/lib/federation/providers';
import { memberMetadata } from '@/lib/federation/metadata';
import createTranslation from 'next-translate/createTranslation';
import SignInButtons from '../components/SignInButtons';

interface SignInPageProps {
    params: { lang: string };
}

/**
 * Federation (fork): choose an identity provider. Which providers appear is
 * decided on the server from the environment; the buttons only start the
 * authorization-code flow.
 */
export function generateMetadata({ params }: SignInPageProps) {
    return memberMetadata(params.lang, 'sign_in');
}

export default function SignInPage({ params }: SignInPageProps) {
    const { t } = createTranslation('federation');
    const providers = configuredFederationProviders();

    return (
        <section aria-labelledby="sign-in-heading">
            <h1 id="sign-in-heading" className="text-2xl font-bold">
                {t('sign_in.heading')}
            </h1>
            <p className="mt-2 text-slate-700">{t('sign_in.intro')}</p>

            {providers.length === 0 ? (
                <p
                    role="status"
                    className="mt-6 rounded border border-amber-300 bg-amber-50 p-4"
                >
                    {t('sign_in.none_configured')}
                </p>
            ) : (
                <SignInButtons
                    providers={providers}
                    callbackUrl={`/${params.lang}/member`}
                />
            )}
        </section>
    );
}
