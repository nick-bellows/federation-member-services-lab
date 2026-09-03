'use client';

import {
    updateApplicationDetails,
    type ActionResult,
} from '@/actions/federation/actions';
import useTranslation from 'next-translate/useTranslation';
import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';
import ActionMessage from '../../components/ActionMessage';

interface Props {
    lang: string;
    applicationId: string;
    editable: boolean;
    dateOfBirth: string;
    phone: string;
    applicantNotes: string;
}

export default function DetailsForm(props: Props) {
    const { t } = useTranslation('federation');
    const router = useRouter();
    const [pending, startTransition] = useTransition();
    const [dateOfBirth, setDateOfBirth] = useState(props.dateOfBirth);
    const [phone, setPhone] = useState(props.phone);
    const [applicantNotes, setApplicantNotes] = useState(props.applicantNotes);
    const [result, setResult] = useState<ActionResult | null>(null);
    const fieldError = (name: string) => result?.fieldErrors?.[name]?.[0];

    if (!props.editable) {
        return (
            <dl className="mt-3 grid grid-cols-1 gap-x-8 gap-y-2 sm:grid-cols-[max-content_1fr]">
                <dt className="font-medium">{t('fields.date_of_birth')}</dt>
                <dd>{props.dateOfBirth || '—'}</dd>
                <dt className="font-medium">{t('fields.phone')}</dt>
                <dd>{props.phone || '—'}</dd>
                <dt className="font-medium">{t('fields.notes')}</dt>
                <dd className="whitespace-pre-wrap">
                    {props.applicantNotes || '—'}
                </dd>
            </dl>
        );
    }

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setResult(null);

        startTransition(async () => {
            const outcome = await updateApplicationDetails({
                applicationId: props.applicationId,
                dateOfBirth,
                phone,
                applicantNotes,
                lang: props.lang,
            });
            setResult(outcome);

            if (outcome.ok) {
                router.refresh();
            }
        });
    }

    return (
        <form onSubmit={submit} className="mt-3 space-y-4">
            <div>
                <label htmlFor="details-dob" className="block font-medium">
                    {t('fields.date_of_birth')}
                </label>
                <input
                    id="details-dob"
                    type="date"
                    value={dateOfBirth}
                    onChange={(e) => setDateOfBirth(e.target.value)}
                    aria-invalid={fieldError('dateOfBirth') ? true : undefined}
                    aria-describedby={
                        fieldError('dateOfBirth')
                            ? 'details-dob-error'
                            : undefined
                    }
                    className="mt-1 rounded border border-slate-500 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2"
                />
                {fieldError('dateOfBirth') && (
                    <p
                        id="details-dob-error"
                        className="mt-1 text-sm text-red-900"
                    >
                        {fieldError('dateOfBirth')}
                    </p>
                )}
            </div>
            <div>
                <label htmlFor="details-phone" className="block font-medium">
                    {t('fields.phone')}
                </label>
                <input
                    id="details-phone"
                    type="tel"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    maxLength={32}
                    className="mt-1 w-full max-w-md rounded border border-slate-500 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2"
                />
            </div>
            <div>
                <label htmlFor="details-notes" className="block font-medium">
                    {t('fields.notes')}
                </label>
                <textarea
                    id="details-notes"
                    value={applicantNotes}
                    onChange={(e) => setApplicantNotes(e.target.value)}
                    maxLength={2000}
                    rows={3}
                    className="mt-1 w-full rounded border border-slate-500 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2"
                />
            </div>
            <button
                type="submit"
                disabled={pending}
                aria-busy={pending}
                className="rounded border border-slate-900 px-4 py-2 font-medium hover:bg-slate-100 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
            >
                {pending ? t('common.working') : t('application.save_details')}
            </button>
            <ActionMessage
                result={result}
                successText={t('application.details_saved')}
            />
        </form>
    );
}
