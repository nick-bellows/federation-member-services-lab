'use server';

import { createFederationClient, jsonApiErrors } from '@/lib/federation/client';
import { getOidcAccessToken } from '@/lib/federation/session';
import { revalidatePath } from 'next/cache';
import { z } from 'zod';
import {
    applicationRoles,
    documentTypes,
    type ApplicationStatus,
} from '@/lib/federation/types';

/**
 * Federation (fork): server actions behind the member and reviewer forms.
 *
 * Every action re-reads the access token from the encrypted cookie, calls
 * the federation API with it, and returns a result the form can render:
 * success with the resource id, or field errors / a message from the API's
 * JSON:API error document. The domain rules live in Laravel; these actions
 * only validate shape before the round trip.
 */
export interface ActionResult {
    ok: boolean;
    id?: string;
    status?: ApplicationStatus;
    message?: string;
    code?: string;
    fieldErrors?: Record<string, string[]>;
    missingDocuments?: string[];
}

async function tokenOrFail(): Promise<
    { token: string } | { failure: ActionResult }
> {
    const token = await getOidcAccessToken();

    if (!token) {
        return {
            failure: {
                ok: false,
                code: 'not_signed_in',
                message: 'Your session has ended. Sign in again.',
            },
        };
    }

    return { token };
}

function failureFrom(error: unknown, response?: Response): ActionResult {
    const errors = jsonApiErrors(error);
    const first = errors[0];
    const fieldErrors: Record<string, string[]> = {};

    for (const e of errors) {
        const pointer = e.source?.pointer ?? '';
        const match = pointer.match(
            /\/data\/(?:attributes|relationships)\/([^/]+)/,
        );

        if (match && e.detail) {
            (fieldErrors[match[1]] ??= []).push(e.detail);
        }
    }

    return {
        ok: false,
        code: first?.code ?? (response ? `http_${response.status}` : 'unknown'),
        message:
            first?.detail ?? first?.title ?? 'The request was not accepted.',
        fieldErrors: Object.keys(fieldErrors).length ? fieldErrors : undefined,
        missingDocuments: Array.isArray(first?.meta?.missingDocuments)
            ? (first.meta.missingDocuments as string[])
            : undefined,
    };
}

function idempotencyKey(): string {
    return `web-${crypto.randomUUID()}`;
}

const startSchema = z.object({
    windowId: z.string().min(1),
    role: z.enum(applicationRoles),
    dateOfBirth: z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/, 'Use the format YYYY-MM-DD'),
    lang: z.string().min(2).max(5),
});

export async function startApplication(
    input: z.infer<typeof startSchema>,
): Promise<ActionResult> {
    const parsed = startSchema.safeParse(input);

    if (!parsed.success) {
        return {
            ok: false,
            code: 'invalid_input',
            fieldErrors: parsed.error.flatten().fieldErrors as Record<
                string,
                string[]
            >,
        };
    }

    const auth = await tokenOrFail();
    if ('failure' in auth) return auth.failure;

    const client = createFederationClient(auth.token);
    const { data, error, response } = await client.POST(
        '/registration-applications',
        {
            headers: { 'Idempotency-Key': idempotencyKey() },
            body: {
                data: {
                    type: 'registration-applications',
                    attributes: {
                        role: parsed.data.role,
                        dateOfBirth: parsed.data.dateOfBirth,
                    },
                    relationships: {
                        registrationWindow: {
                            data: {
                                type: 'registration-windows',
                                id: parsed.data.windowId,
                            },
                        },
                    },
                },
            } as never,
        },
    );

    if (error || !data) {
        return failureFrom(error, response);
    }

    revalidatePath(`/${parsed.data.lang}/member/applications`);

    return { ok: true, id: (data as { data: { id: string } }).data.id };
}

const detailsSchema = z.object({
    applicationId: z.string().min(1),
    dateOfBirth: z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/, 'Use the format YYYY-MM-DD')
        .or(z.literal('')),
    phone: z.string().max(32).optional().or(z.literal('')),
    applicantNotes: z.string().max(2000).optional().or(z.literal('')),
    lang: z.string().min(2).max(5),
});

export async function updateApplicationDetails(
    input: z.infer<typeof detailsSchema>,
): Promise<ActionResult> {
    const parsed = detailsSchema.safeParse(input);

    if (!parsed.success) {
        return {
            ok: false,
            code: 'invalid_input',
            fieldErrors: parsed.error.flatten().fieldErrors as Record<
                string,
                string[]
            >,
        };
    }

    const auth = await tokenOrFail();
    if ('failure' in auth) return auth.failure;

    const client = createFederationClient(auth.token);
    const { error, response } = await client.PATCH(
        '/registration-applications/{registration_application}',
        {
            params: {
                path: { registration_application: parsed.data.applicationId },
            },
            body: {
                data: {
                    type: 'registration-applications',
                    id: parsed.data.applicationId,
                    attributes: {
                        dateOfBirth: parsed.data.dateOfBirth || null,
                        phone: parsed.data.phone || null,
                        applicantNotes: parsed.data.applicantNotes || null,
                    },
                },
            } as never,
        },
    );

    if (error) {
        return failureFrom(error, response);
    }

    revalidatePath(
        `/${parsed.data.lang}/member/applications/${parsed.data.applicationId}`,
    );

    return { ok: true, id: parsed.data.applicationId };
}

const documentSchema = z.object({
    applicationId: z.string().min(1),
    documentType: z.enum(documentTypes),
    fileName: z.string().min(1).max(255),
    mimeType: z.enum(['application/pdf', 'image/jpeg', 'image/png']),
    sizeBytes: z
        .number()
        .int()
        .min(1)
        .max(10 * 1024 * 1024),
    checksumSha256: z.string().regex(/^[a-f0-9]{64}$/),
    lang: z.string().min(2).max(5),
});

export async function attachDocumentMetadata(
    input: z.infer<typeof documentSchema>,
): Promise<ActionResult> {
    const parsed = documentSchema.safeParse(input);

    if (!parsed.success) {
        return {
            ok: false,
            code: 'invalid_input',
            fieldErrors: parsed.error.flatten().fieldErrors as Record<
                string,
                string[]
            >,
        };
    }

    const auth = await tokenOrFail();
    if ('failure' in auth) return auth.failure;

    const client = createFederationClient(auth.token);
    const { applicationId, lang, ...attributes } = parsed.data;
    const { error, response } = await client.POST('/application-documents', {
        body: {
            data: {
                type: 'application-documents',
                attributes,
                relationships: {
                    application: {
                        data: {
                            type: 'registration-applications',
                            id: applicationId,
                        },
                    },
                },
            },
        } as never,
    });

    if (error) {
        return failureFrom(error, response);
    }

    revalidatePath(`/${lang}/member/applications/${applicationId}`);
    revalidatePath(`/${lang}/member/review/${applicationId}`);

    return { ok: true, id: applicationId };
}

const transitionSchema = z.object({
    applicationId: z.string().min(1),
    action: z.enum([
        'submit',
        'cancel',
        'start-review',
        'request-information',
        'approve',
        'reject',
    ]),
    reason: z.string().max(2000).optional().or(z.literal('')),
    attemptKey: z
        .string()
        .regex(/^[A-Za-z0-9._-]{8,64}$/)
        .optional(),
    lang: z.string().min(2).max(5),
});

export async function transitionApplication(
    input: z.infer<typeof transitionSchema>,
): Promise<ActionResult> {
    const parsed = transitionSchema.safeParse(input);

    if (!parsed.success) {
        return {
            ok: false,
            code: 'invalid_input',
            fieldErrors: parsed.error.flatten().fieldErrors as Record<
                string,
                string[]
            >,
        };
    }

    const auth = await tokenOrFail();
    if ('failure' in auth) return auth.failure;

    const client = createFederationClient(auth.token);
    const path =
        `/registration-applications/{registration_application}/-actions/${parsed.data.action}` as const;
    const { data, error, response } = await client.POST(
        path as never,
        {
            params: {
                path: { registration_application: parsed.data.applicationId },
            },
            headers: {
                'Idempotency-Key': parsed.data.attemptKey ?? idempotencyKey(),
            },
            body: parsed.data.reason
                ? { meta: { reason: parsed.data.reason } }
                : {},
        } as never,
    );

    if (error || !data) {
        return failureFrom(error, response);
    }

    const { lang, applicationId } = parsed.data;
    revalidatePath(`/${lang}/member/applications`);
    revalidatePath(`/${lang}/member/applications/${applicationId}`);
    revalidatePath(`/${lang}/member/review`);
    revalidatePath(`/${lang}/member/review/${applicationId}`);

    return {
        ok: true,
        id: applicationId,
        status: (
            data as { data: { attributes: { status: ApplicationStatus } } }
        ).data.attributes.status,
    };
}

const refreshCredentialsSchema = z.object({
    applicationId: z.string().min(1),
    lang: z.string().min(2).max(5),
});

/**
 * A reviewer asks the API to fetch the applicant's credentials from the
 * Learning Center now. The only user action that reaches the provider;
 * a 503 from the API means "not now", and the page keeps the last snapshot.
 */
export async function refreshCredentials(
    input: z.infer<typeof refreshCredentialsSchema>,
): Promise<ActionResult> {
    const parsed = refreshCredentialsSchema.safeParse(input);

    if (!parsed.success) {
        return { ok: false, code: 'invalid_input' };
    }

    const auth = await tokenOrFail();
    if ('failure' in auth) return auth.failure;

    const client = createFederationClient(auth.token);
    const { data, error, response } = await client.POST(
        '/registration-applications/{registration_application}/-actions/refresh-credentials',
        {
            params: {
                path: { registration_application: parsed.data.applicationId },
            },
        },
    );

    if (error || !data) {
        return failureFrom(error, response);
    }

    const { lang, applicationId } = parsed.data;
    revalidatePath(`/${lang}/member/applications/${applicationId}`);
    revalidatePath(`/${lang}/member/review`);
    revalidatePath(`/${lang}/member/review/${applicationId}`);

    return { ok: true, id: applicationId };
}

const reviewDocumentSchema = z.object({
    documentId: z.string().min(1),
    applicationId: z.string().min(1),
    reviewStatus: z.enum(['accepted', 'rejected']),
    reviewNote: z.string().max(1000).optional().or(z.literal('')),
    lang: z.string().min(2).max(5),
});

export async function reviewDocument(
    input: z.infer<typeof reviewDocumentSchema>,
): Promise<ActionResult> {
    const parsed = reviewDocumentSchema.safeParse(input);

    if (!parsed.success) {
        return {
            ok: false,
            code: 'invalid_input',
            fieldErrors: parsed.error.flatten().fieldErrors as Record<
                string,
                string[]
            >,
        };
    }

    const auth = await tokenOrFail();
    if ('failure' in auth) return auth.failure;

    const client = createFederationClient(auth.token);
    const { error, response } = await client.PATCH(
        '/application-documents/{application_document}',
        {
            params: { path: { application_document: parsed.data.documentId } },
            body: {
                data: {
                    type: 'application-documents',
                    id: parsed.data.documentId,
                    attributes: {
                        reviewStatus: parsed.data.reviewStatus,
                        reviewNote: parsed.data.reviewNote || null,
                    },
                },
            } as never,
        },
    );

    if (error) {
        return failureFrom(error, response);
    }

    revalidatePath(
        `/${parsed.data.lang}/member/review/${parsed.data.applicationId}`,
    );

    return { ok: true, id: parsed.data.applicationId };
}

const windowSchema = z.object({
    organizationId: z.string().min(1),
    seasonId: z.string().min(1),
    opensAt: z.string().min(1),
    closesAt: z.string().min(1),
    roles: z.array(z.enum(applicationRoles)).min(1),
    lang: z.string().min(2).max(5),
});

export async function openRegistrationWindow(
    input: z.infer<typeof windowSchema>,
): Promise<ActionResult> {
    const parsed = windowSchema.safeParse(input);

    if (!parsed.success) {
        return {
            ok: false,
            code: 'invalid_input',
            fieldErrors: parsed.error.flatten().fieldErrors as Record<
                string,
                string[]
            >,
        };
    }

    const auth = await tokenOrFail();
    if ('failure' in auth) return auth.failure;

    const client = createFederationClient(auth.token);
    const { data, error, response } = await client.POST(
        '/registration-windows',
        {
            body: {
                data: {
                    type: 'registration-windows',
                    attributes: {
                        opensAt: parsed.data.opensAt,
                        closesAt: parsed.data.closesAt,
                        roles: parsed.data.roles,
                    },
                    relationships: {
                        memberOrganization: {
                            data: {
                                type: 'member-organizations',
                                id: parsed.data.organizationId,
                            },
                        },
                        season: {
                            data: { type: 'seasons', id: parsed.data.seasonId },
                        },
                    },
                },
            } as never,
        },
    );

    if (error || !data) {
        return failureFrom(error, response);
    }

    revalidatePath(`/${parsed.data.lang}/member/windows`);

    return { ok: true, id: (data as { data: { id: string } }).data.id };
}
