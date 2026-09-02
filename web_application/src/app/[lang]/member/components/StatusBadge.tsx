import type {
    ApplicationStatus,
    DocumentReviewStatus,
} from '@/lib/federation/types';

const applicationClasses: Record<ApplicationStatus, string> = {
    draft: 'bg-slate-100 text-slate-900 border-slate-400',
    submitted: 'bg-blue-50 text-blue-900 border-blue-300',
    under_review: 'bg-amber-50 text-amber-900 border-amber-400',
    needs_information: 'bg-orange-50 text-orange-900 border-orange-400',
    approved: 'bg-green-50 text-green-900 border-green-400',
    rejected: 'bg-red-50 text-red-900 border-red-400',
    cancelled: 'bg-slate-100 text-slate-700 border-slate-300',
};

const documentClasses: Record<DocumentReviewStatus, string> = {
    pending: 'bg-slate-100 text-slate-900 border-slate-400',
    accepted: 'bg-green-50 text-green-900 border-green-400',
    rejected: 'bg-red-50 text-red-900 border-red-400',
};

/**
 * Status as text first, colour second: the label carries the meaning, the
 * background only reinforces it.
 */
export function ApplicationStatusBadge({
    status,
    label,
}: {
    status: ApplicationStatus;
    label: string;
}) {
    return (
        <span
            className={`inline-block rounded border px-2 py-0.5 text-sm font-medium ${applicationClasses[status]}`}
            data-status={status}
        >
            {label}
        </span>
    );
}

export function DocumentStatusBadge({
    status,
    label,
}: {
    status: DocumentReviewStatus;
    label: string;
}) {
    return (
        <span
            className={`inline-block rounded border px-2 py-0.5 text-sm font-medium ${documentClasses[status]}`}
            data-status={status}
        >
            {label}
        </span>
    );
}
