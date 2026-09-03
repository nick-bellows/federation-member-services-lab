import { requireFederationClient } from '@/lib/federation/client';
import { listOpenWindows } from '@/lib/federation/queries';
import createTranslation from 'next-translate/createTranslation';
import StartApplicationForm from './StartApplicationForm';

interface PageProps {
    params: { lang: string };
}

export default async function NewApplicationPage({ params }: PageProps) {
    const { t } = createTranslation('federation');
    const client = await requireFederationClient(params.lang);
    const windows = await listOpenWindows(client);

    return (
        <section aria-labelledby="new-application-heading">
            <h1 id="new-application-heading" className="text-2xl font-bold">
                {t('start.heading')}
            </h1>
            <p className="mt-2 text-slate-700">{t('start.intro')}</p>

            {windows.length === 0 ? (
                <p
                    role="status"
                    className="mt-6 rounded border border-amber-300 bg-amber-50 p-4"
                >
                    {t('start.no_windows')}
                </p>
            ) : (
                <StartApplicationForm lang={params.lang} windows={windows} />
            )}
        </section>
    );
}
