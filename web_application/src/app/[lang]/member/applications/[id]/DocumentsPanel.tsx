'use client';

import {
    attachDocumentMetadata,
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
    editable: boolean;
    required: DocumentType[];
    documents: (DocumentAttributes & { id: string })[];
}

const acceptedMimeTypes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
] as const;

async function sha256Hex(file: File): Promise<string> {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        await file.arrayBuffer(),
    );

    return Array.from(new Uint8Array(digest))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
}

/**
 * Document metadata only: the browser hashes the chosen file locally and
 * sends name, type, size and checksum. No bytes leave the device in this
 * milestone (ADR-0008).
 */
export default function DocumentsPanel({
    lang,
    applicationId,
    editable,
    required,
    documents,
}: Props) {
    const { t } = useTranslation('federation');
    const router = useRouter();
    const [pending, startTransition] = useTransition();
    const [busyType, setBusyType] = useState<DocumentType | null>(null);
    const [result, setResult] = useState<ActionResult | null>(null);

    function attach(type: DocumentType, file: File | undefined) {
        if (!file) {
            return;
        }

        setResult(null);
        setBusyType(type);

        startTransition(async () => {
            if (!(acceptedMimeTypes as readonly string[]).includes(file.type)) {
                setResult({
                    ok: false,
                    code: 'document_not_allowed',
                    message: t('documents.format_error'),
                });
                setBusyType(null);

                return;
            }

            const outcome = await attachDocumentMetadata({
                applicationId,
                documentType: type,
                fileName: file.name,
                mimeType: file.type as (typeof acceptedMimeTypes)[number],
                sizeBytes: file.size,
                checksumSha256: await sha256Hex(file),
                lang,
            });

            setResult(outcome);
            setBusyType(null);

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
                    const inputId = `document-${type}`;

                    return (
                        <li
                            key={type}
                            className="flex flex-wrap items-center justify-between gap-3 p-3"
                        >
                            <div>
                                <p className="font-medium">
                                    {t(`document_types.${type}`)}
                                </p>
                                {document ? (
                                    <p className="text-sm text-slate-700">
                                        {document.fileName} ·{' '}
                                        {Math.round(document.sizeBytes / 1024)}{' '}
                                        kB
                                        {document.reviewNote && (
                                            <span className="block">
                                                {t('documents.note')}:{' '}
                                                {document.reviewNote}
                                            </span>
                                        )}
                                    </p>
                                ) : (
                                    <p className="text-sm text-slate-700">
                                        {t('documents.missing')}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                {document && (
                                    <DocumentStatusBadge
                                        status={document.reviewStatus}
                                        label={t(
                                            `document_statuses.${document.reviewStatus}`,
                                        )}
                                    />
                                )}
                                {editable && (
                                    <label className="cursor-pointer rounded border border-slate-900 px-3 py-1.5 text-sm font-medium focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 hover:bg-slate-100">
                                        {busyType === type && pending
                                            ? t('common.working')
                                            : document
                                              ? t('documents.replace')
                                              : t('documents.choose')}
                                        <input
                                            id={inputId}
                                            type="file"
                                            accept={acceptedMimeTypes.join(',')}
                                            className="sr-only"
                                            aria-label={t(
                                                'documents.choose_for',
                                                {
                                                    type: t(
                                                        `document_types.${type}`,
                                                    ),
                                                },
                                            )}
                                            disabled={pending}
                                            onChange={(e) =>
                                                attach(
                                                    type,
                                                    e.target.files?.[0],
                                                )
                                            }
                                        />
                                    </label>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ul>
            <ActionMessage
                result={result}
                successText={t('documents.attached')}
            />
        </div>
    );
}
