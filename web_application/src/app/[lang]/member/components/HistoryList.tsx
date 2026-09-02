import type { HistoryEntry } from '@/lib/federation/types';
import createTranslation from 'next-translate/createTranslation';

/**
 * The audit trail as a readable list: newest last, so it reads as a story.
 */
export default function HistoryList({ entries }: { entries: HistoryEntry[] }) {
    const { t } = createTranslation('federation');

    if (entries.length === 0) {
        return <p className="mt-3 text-slate-700">{t('history.none')}</p>;
    }

    return (
        <ol className="mt-3 space-y-2 border-l-2 border-slate-300 pl-4">
            {entries.map((entry, index) => (
                <li key={`${entry.action}-${index}`} className="text-sm">
                    <time
                        dateTime={entry.occurredAt ?? undefined}
                        className="block text-slate-700"
                    >
                        {entry.occurredAt
                            ? new Date(entry.occurredAt).toLocaleString()
                            : ''}
                    </time>
                    <span className="font-medium">
                        {t(
                            `history.actions.${entry.action.replace('.', '_')}`,
                            {},
                            { default: entry.action },
                        )}
                    </span>
                    {entry.documentType && (
                        <span>
                            {' '}
                            ·{' '}
                            {t(
                                `document_types.${entry.documentType}`,
                                {},
                                { default: entry.documentType },
                            )}
                        </span>
                    )}
                    {entry.actor && <span> · {entry.actor}</span>}
                    {entry.reason && (
                        <span className="block italic">“{entry.reason}”</span>
                    )}
                </li>
            ))}
        </ol>
    );
}
