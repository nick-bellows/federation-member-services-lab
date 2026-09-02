import type { ActionResult } from '@/actions/federation/actions';

/**
 * One place to announce what a server action answered. role="alert" for
 * failures so screen readers hear it immediately; role="status" for success.
 */
export default function ActionMessage({
    result,
    successText,
    id,
}: {
    result: ActionResult | null;
    successText?: string;
    id?: string;
}) {
    if (!result) {
        return null;
    }

    if (result.ok) {
        return successText ? (
            <p
                id={id}
                role="status"
                className="mt-3 rounded border border-green-400 bg-green-50 p-3 text-green-900"
            >
                {successText}
            </p>
        ) : null;
    }

    return (
        <div
            id={id}
            role="alert"
            className="mt-3 rounded border border-red-400 bg-red-50 p-3 text-red-900"
        >
            <p>{result.message}</p>
            {result.missingDocuments && result.missingDocuments.length > 0 && (
                <ul className="mt-2 list-disc pl-5 text-sm">
                    {result.missingDocuments.map((type) => (
                        <li key={type}>{type.replaceAll('_', ' ')}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}
