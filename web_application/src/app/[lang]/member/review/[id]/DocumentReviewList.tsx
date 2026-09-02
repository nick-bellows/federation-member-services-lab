'use client';

import {
    reviewDocument,
    type ActionResult,
} from '@/actions/federation/actions';
import type { DocumentAttributes, DocumentType } from '@/lib/federation/types';
import useTranslation from 'next-translate/useTranslation';
import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';
import ActionMessage from '../../components/ActionMessage';
import { DocumentStatusBadge } from '../../components/StatusBadge';

interface Props {
    lang: string;
    applicationId: string;
    reviewable: boolean;
    required: DocumentType[];
    documents: (DocumentAttributes & { id: string })[];
}

export default function DocumentReviewList({
    lang,
    applicationId,
    reviewable,
    required,
    documents,
}: Props) {
    const { t } = useTranslation('federation');
    const router = useRouter();
    const [pending, startTransition] = useTransition();
    const [notes, setNotes] = useState<Record<string, string>>({});
    const [result, setResult] = useState<ActionResult | null>(null);

    function decide(documentId: string, reviewStatus: 'accepted' | 'rejected') {
        setResult(null);

        startTransition(async () => {
            const outcome = await reviewDocument({
                documentId,
                applicationId,
                reviewStatus,
                reviewNote: notes[documentId] ?? '',
                lang,
            });
            setResult(outcome);

            if (outcome.ok) {
                router.refresh();
            }
        });
    }

    return (
        <div className="mt-3">
            <ul className="divide-y divide-slate-200 rounded border border-slate-300">
                {required.map((type) => {
                    const document = documents.find(
                        (d) => d.documentType === type,
                    );

                    return (
                        <li key={type} className="p-3">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="font-medium">
                                        {t(`document_types.${type}`)}
                                    </p>
                                    {document ? (
                                        <p className="text-sm text-slate-700">
                                            {document.fileName} ·{' '}
                                            {Math.round(
                                                document.sizeBytes / 1024,
                                            )}{' '}
                                            kB ·{' '}
                                            <code className="text-xs">
                                                {document.checksumSha256.slice(
                                                    0,
                                                    12,
                                                )}
                                                …
                                            </code>
                                        </p>
                                    ) : (
                                        <p className="text-sm text-slate-700">
                                            {t('documents.missing')}
                                        </p>
                                    )}
                                </div>
                                {document && (
                                    <DocumentStatusBadge
                                        status={document.reviewStatus}
                                        label={t(
                                            `document_statuses.${document.reviewStatus}`,
                                        )}
                                    />
                                )}
                            </div>
                            {document?.reviewNote && (
                                <p className="mt-1 text-sm">
                                    {t('documents.note')}: {document.reviewNote}
                                </p>
                            )}
                            {document && reviewable && (
                                <div className="mt-3 flex flex-wrap items-end gap-3">
                                    <div className="flex-1">
                                        <label
                                            htmlFor={`note-${document.id}`}
                                            className="block text-sm font-medium"
                                        >
                                            {t('documents.note')}
                                        </label>
                                        <input
                                            id={`note-${document.id}`}
                                            type="text"
                                            value={notes[document.id] ?? ''}
                                            onChange={(e) =>
                                                setNotes({
                                                    ...notes,
                                                    [document.id]:
                                                        e.target.value,
                                                })
                                            }
                                            maxLength={1000}
                                            className="mt-1 w-full rounded border border-slate-500 px-3 py-1.5 text-sm focus:outline focus:outline-2 focus:outline-offset-2"
                                        />
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            decide(document.id, 'accepted')
                                        }
                                        disabled={pending}
                                        className="rounded border border-green-800 px-3 py-1.5 text-sm font-medium text-green-900 hover:bg-green-50 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
                                    >
                                        {t('documents.accept')}
                                        <span className="sr-only">
                                            {' '}
                                            {t(`document_types.${type}`)}
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            decide(document.id, 'rejected')
                                        }
                                        disabled={pending}
                                        className="rounded border border-red-700 px-3 py-1.5 text-sm font-medium text-red-900 hover:bg-red-50 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
                                    >
                                        {t('documents.reject')}
                                        <span className="sr-only">
                                            {' '}
                                            {t(`document_types.${type}`)}
                                        </span>
                                    </button>
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
            <ActionMessage
                result={result}
                successText={t('documents.reviewed')}
            />
        </div>
    );
}
