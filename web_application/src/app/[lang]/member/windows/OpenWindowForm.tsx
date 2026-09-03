'use client';

import {
    openRegistrationWindow,
    type ActionResult,
} from '@/actions/federation/actions';
import { applicationRoles, type ApplicationRole } from '@/lib/federation/types';
import useTranslation from 'next-translate/useTranslation';
import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';
import ActionMessage from '../components/ActionMessage';

interface Props {
    lang: string;
    organizations: { id: string; name: string; code: string }[];
    seasons: { id: string; label: string }[];
}

export default function OpenWindowForm({
    lang,
    organizations,
    seasons,
}: Props) {
    const { t } = useTranslation('federation');
    const router = useRouter();
    const [pending, startTransition] = useTransition();
    const [organizationId, setOrganizationId] = useState(
        organizations[0]?.id ?? '',
    );
    const [seasonId, setSeasonId] = useState(seasons[0]?.id ?? '');
    const [opensAt, setOpensAt] = useState('');
    const [closesAt, setClosesAt] = useState('');
    const [roles, setRoles] = useState<ApplicationRole[]>([
        ...applicationRoles,
    ]);
    const [result, setResult] = useState<ActionResult | null>(null);
    const fieldError = (name: string) => result?.fieldErrors?.[name]?.[0];

    function toggle(role: ApplicationRole) {
        setRoles((current) =>
            current.includes(role)
                ? current.filter((r) => r !== role)
                : [...current, role],
        );
    }

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setResult(null);

        startTransition(async () => {
            const outcome = await openRegistrationWindow({
                organizationId,
                seasonId,
                opensAt: opensAt ? new Date(opensAt).toISOString() : '',
                closesAt: closesAt ? new Date(closesAt).toISOString() : '',
                roles,
                lang,
            });
            setResult(outcome);

            if (outcome.ok) {
                router.refresh();
            }
        });
    }

    const inputClass =
        'mt-1 rounded border border-slate-500 px-3 py-2 focus:outline focus:outline-2 focus:outline-offset-2';

    return (
        <form onSubmit={submit} className="mt-3 space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label
                        htmlFor="window-organization"
                        className="block font-medium"
                    >
                        {t('fields.organization')}
                    </label>
                    <select
                        id="window-organization"
                        value={organizationId}
                        onChange={(e) => setOrganizationId(e.target.value)}
                        className={`${inputClass} w-full`}
                    >
                        {organizations.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.name} ({o.code})
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label
                        htmlFor="window-season"
                        className="block font-medium"
                    >
                        {t('fields.season')}
                    </label>
                    <select
                        id="window-season"
                        value={seasonId}
                        onChange={(e) => setSeasonId(e.target.value)}
                        className={`${inputClass} w-full`}
                    >
                        {seasons.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <label htmlFor="window-opens" className="block font-medium">
                        {t('windows.opens')}
                    </label>
                    <input
                        id="window-opens"
                        type="datetime-local"
                        required
                        value={opensAt}
                        onChange={(e) => setOpensAt(e.target.value)}
                        className={inputClass}
                        aria-invalid={fieldError('opensAt') ? true : undefined}
                    />
                    {fieldError('opensAt') && (
                        <p className="mt-1 text-sm text-red-900">
                            {fieldError('opensAt')}
                        </p>
                    )}
                </div>
                <div>
                    <label
                        htmlFor="window-closes"
                        className="block font-medium"
                    >
                        {t('windows.closes')}
                    </label>
                    <input
                        id="window-closes"
                        type="datetime-local"
                        required
                        value={closesAt}
                        onChange={(e) => setClosesAt(e.target.value)}
                        className={inputClass}
                        aria-invalid={fieldError('closesAt') ? true : undefined}
                        aria-describedby={
                            fieldError('closesAt')
                                ? 'window-closes-error'
                                : undefined
                        }
                    />
                    {fieldError('closesAt') && (
                        <p
                            id="window-closes-error"
                            className="mt-1 text-sm text-red-900"
                        >
                            {fieldError('closesAt')}
                        </p>
                    )}
                </div>
            </div>
            <fieldset>
                <legend className="font-medium">{t('windows.roles')}</legend>
                <div className="mt-2 flex flex-wrap gap-4">
                    {applicationRoles.map((role) => (
                        <label key={role} className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={roles.includes(role)}
                                onChange={() => toggle(role)}
                                className="h-4 w-4"
                            />
                            {t(`roles.${role}`)}
                        </label>
                    ))}
                </div>
            </fieldset>
            <button
                type="submit"
                disabled={pending || roles.length === 0}
                aria-busy={pending}
                className="rounded border border-slate-900 bg-slate-900 px-4 py-2 font-medium text-white hover:bg-slate-700 focus:outline focus:outline-2 focus:outline-offset-2 disabled:opacity-60"
            >
                {pending ? t('common.working') : t('windows.open_submit')}
            </button>
            <ActionMessage result={result} successText={t('windows.opened')} />
        </form>
    );
}
