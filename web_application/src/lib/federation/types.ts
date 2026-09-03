/**
 * Federation (fork): view-side shapes for JSON:API documents from the
 * federation server. The generated schema types describe documents; these
 * flatten one resource into what a page needs.
 */
export const applicationRoles = ['participant', 'coach', 'referee'] as const;
export type ApplicationRole = (typeof applicationRoles)[number];

export const applicationStatuses = [
    'draft',
    'submitted',
    'under_review',
    'needs_information',
    'approved',
    'rejected',
    'cancelled',
] as const;
export type ApplicationStatus = (typeof applicationStatuses)[number];

export const documentTypes = [
    'proof_of_age',
    'photo',
    'coaching_licence',
    'referee_certificate',
    'background_check_consent',
] as const;
export type DocumentType = (typeof documentTypes)[number];

export type DocumentReviewStatus = 'pending' | 'accepted' | 'rejected';

export interface JsonApiResource<A extends Record<string, unknown>> {
    id: string;
    type: string;
    attributes: A;
    relationships?: Record<
        string,
        {
            data:
                | { type: string; id: string }
                | { type: string; id: string }[]
                | null;
        }
    >;
}

export interface HistoryEntry {
    action: string;
    occurredAt: string | null;
    actor: string | null;
    from: string | null;
    to: string | null;
    reason: string | null;
    documentType: string | null;
}

export const participationStatuses = [
    'may_participate',
    'blocked',
    'unknown',
] as const;
export type ParticipationStatus = (typeof participationStatuses)[number];

export const participationReasons = [
    'not_approved',
    'no_snapshot',
    'no_learning_center_record',
    'hold_active',
    'credential_lapsed',
    'role_credential_missing',
] as const;
export type ParticipationReason = (typeof participationReasons)[number];

/**
 * Derived by the API from the application and the applicant's credential
 * snapshot (docs/contracts/learning-center-credentials-v1.md).
 */
export interface Participation {
    status: ParticipationStatus;
    reasons: ParticipationReason[];
    asOf: string | null;
    fetchedAt: string | null;
    stale: boolean;
}

export interface ApplicationAttributes extends Record<string, unknown> {
    role: ApplicationRole;
    status: ApplicationStatus;
    statusReason: string | null;
    dateOfBirth: string | null;
    phone: string | null;
    applicantNotes: string | null;
    missingRequiredDocuments: DocumentType[];
    history: HistoryEntry[];
    participation: Participation;
    submittedAt: string | null;
    decidedAt: string | null;
    cancelledAt: string | null;
    createdAt: string;
}

export interface DocumentAttributes extends Record<string, unknown> {
    documentType: DocumentType;
    fileName: string;
    mimeType: string;
    sizeBytes: number;
    checksumSha256: string;
    reviewStatus: DocumentReviewStatus;
    reviewNote: string | null;
    reviewedAt: string | null;
}

export interface WindowAttributes extends Record<string, unknown> {
    opensAt: string;
    closesAt: string;
    roles: ApplicationRole[];
    isOpen: 'true' | 'false';
}

export interface OrganizationAttributes extends Record<string, unknown> {
    name: string;
    code: string;
}

export interface SeasonAttributes extends Record<string, unknown> {
    label: string;
    startsOn: string;
    endsOn: string;
}

export interface UserAttributes extends Record<string, unknown> {
    name: string;
    email: string;
}

export type ApplicationResource = JsonApiResource<ApplicationAttributes>;
export type DocumentResource = JsonApiResource<DocumentAttributes>;
export type WindowResource = JsonApiResource<WindowAttributes>;

/**
 * Pulls an included resource by type and id out of a compound document.
 */
export function findIncluded<A extends Record<string, unknown>>(
    included: JsonApiResource<Record<string, unknown>>[] | undefined,
    type: string,
    id: string | undefined,
): JsonApiResource<A> | undefined {
    if (!included || !id) {
        return undefined;
    }

    return included.find((r) => r.type === type && r.id === id) as
        JsonApiResource<A> | undefined;
}

export function relatedId(
    resource: JsonApiResource<Record<string, unknown>>,
    relation: string,
): string | undefined {
    const data = resource.relationships?.[relation]?.data;

    return data && !Array.isArray(data) ? data.id : undefined;
}

export function relatedIds(
    resource: JsonApiResource<Record<string, unknown>>,
    relation: string,
): string[] {
    const data = resource.relationships?.[relation]?.data;

    return Array.isArray(data) ? data.map((d) => d.id) : [];
}

export const requiredDocumentsByRole: Record<ApplicationRole, DocumentType[]> =
    {
        participant: ['proof_of_age', 'photo'],
        coach: [
            'proof_of_age',
            'photo',
            'coaching_licence',
            'background_check_consent',
        ],
        referee: [
            'proof_of_age',
            'photo',
            'referee_certificate',
            'background_check_consent',
        ],
    };

export const editableStatuses: ApplicationStatus[] = [
    'draft',
    'needs_information',
];
