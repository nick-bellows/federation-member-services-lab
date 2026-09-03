'use client';

import {
    startApplication,
    type ActionResult,
} from '@/actions/federation/actions';
import { formatDate } from '@/lib/federation/format';
import type { WindowView } from '@/lib/federation/queries';
import { applicationRoles, type ApplicationRole } from '@/lib/federation/types';
import useTranslation from 'next-translate/useTranslation';
import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';
import ActionMessage from '../../components/ActionMessage';

interface Props {
    lang: string;
    windows: WindowView[];
}

export default function StartApplicationForm({ lang, windows }: Props) {
    const { t } = useTranslation('federation');
    const router = useRouter();
    const [pending, startTransition] = useTransition();
    const [windowId, setWindowId] = useState(windows[0]?.id ?? '');
    const [role, setRole] = useState<ApplicationRole>('participant');
    const [dateOfBirth, setDateOfBirth] = useState('');
    const [result, setResult] = useState<ActionResult | null>(null);

    const selectedWindow = windows.find((w) => w.id === windowId);
    const offeredRoles = applicationRoles.filter((r) =>
        selectedWindow?.roles.includes(r),
    );
    const fieldError = (name: string) => result?.fieldErrors?.[name]?.[0];

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setResult(null);

        startTransition(async () => {
            const outcome = await startApplication({
                windowId,
                role,
                dateOfBirth,
                lang,
            });
            setResult(outcome);

            if (outcome.ok && outcome.id) {
                router.push(`/${lang}/member/applications/${outcome.id}`);
            }
        });
    }

    return (
        <form
            onSubmit={submit}
            className="mt-6 space-y-6"
            aria-describedby={
                result && !result.ok ? 'start-message' : undefined
            }
        >
            <div>
                <label htmlFor="window" className="block font-medium">
                    {t('start.window')}
                </label>
                <select
                    id="window"
                    name="windowId"
                    value={windowId}
                    onChange={(e) => {
                        setWindowId(e.target.value);
                        setRole('participant');
                    }}
                    className="mt-1 w-full rounded border border-slate-500 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2"
                    aria-describedby="window-help"
                >
                    {windows.map((w) => (
                        <option key={w.id} value={w.id}>
                            {w.organization?.name ?? '?'} ·{' '}
                            {w.season?.label ?? '?'} ·{' '}
                            {t('start.closes', {
                                date: formatDate(w.closesAt, lang),
                            })}
                        </option>
                    ))}
                </select>
                <p id="window-help" className="mt-1 text-sm text-slate-700">
                    {t('start.window_help')}
                </p>
            </div>

            <fieldset>
                <legend className="font-medium">{t('fields.role')}</legend>
                <div className="mt-2 space-y-2">
                    {offeredRoles.map((r) => (
                        <label key={r} className="flex items-center gap-2">
                            <input
                                type="radio"
                                name="role"
                                value={r}
                                checked={role === r}
                                onChange={() => setRole(r)}
                                className="h-4 w-4"
                            />
                            {t(`roles.${r}`)}
                        </label>
                    ))}
                </div>
                {fieldError('role') && (
                    <p className="mt-1 text-sm text-red-900" role="alert">
                        {fieldError('role')}
                    </p>
                )}
            </fieldset>

            <div>
                <label htmlFor="dateOfBirth" className="block font-medium">
                    {t('fields.date_of_birth')}
                </label>
                <input
                    id="dateOfBirth"
                    name="dateOfBirth"
                    type="date"
                    required
                    value={dateOfBirth}
                    onChange={(e) => setDateOfBirth(e.target.value)}
                    aria-invalid={fieldError('dateOfBirth') ? true : undefined}
                    aria-describedby={
                        fieldError('dateOfBirth')
                            ? 'dateOfBirth-error'
                            : undefined
                    }
                    className="mt-1 rounded border border-slate-500 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2"
                />
                {fieldError('dateOfBirth') && (
                    <p
                        id="dateOfBirth-error"
                        className="mt-1 text-sm text-red-900"
                    >
                        {fieldError('dateOfBirth')}
                    </p>
                )}
            </div>

            <button
                type="submit"
                disabled={pending || !windowId}
                aria-busy={pending}
                className="rounded border border-slate-900 bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
            >
                {pending ? t('common.working') : t('start.submit')}
            </button>

            <ActionMessage id="start-message" result={result} />
        </form>
    );
}
