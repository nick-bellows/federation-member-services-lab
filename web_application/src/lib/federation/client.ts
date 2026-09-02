import 'server-only';

import type { paths } from '@/types/schema_federation';
import createClient from 'openapi-fetch';
import { redirect } from 'next/navigation';
import { getOidcAccessToken } from './session';

export type FederationClient = ReturnType<typeof createClient<paths>>;

/**
 * Federation (fork): typed client for the federation JSON:API server,
 * generated from api/public/federation_openapi.json
 * (`npm run generate-schema:federation`). Server-side only: the access token
 * never leaves the Next server.
 */
export function createFederationClient(accessToken: string): FederationClient {
    return createClient<paths>({
        baseUrl: `${process.env.API_DOMAIN ?? ''}${process.env.API_PATH ?? ''}/federation`,
        headers: {
            Accept: 'application/vnd.api+json',
            'Content-Type': 'application/vnd.api+json',
            Authorization: `Bearer ${accessToken}`,
        },
        querySerializer: { array: { style: 'form', explode: false } },
    });
}

/**
 * The client for the signed-in member, or a redirect to sign-in.
 */
export async function requireFederationClient(
    lang: string,
): Promise<FederationClient> {
    const token = await getOidcAccessToken();

    if (!token) {
        redirect(`/${lang}/member/sign-in`);
    }

    return createFederationClient(token);
}

export interface JsonApiError {
    status?: string;
    code?: string;
    title?: string;
    detail?: string;
    source?: { pointer?: string };
    meta?: Record<string, unknown>;
}

/**
 * Normalises an openapi-fetch error payload into the JSON:API errors array.
 */
export function jsonApiErrors(error: unknown): JsonApiError[] {
    if (error && typeof error === 'object' && 'errors' in error) {
        const errors = (error as { errors?: unknown }).errors;

        if (Array.isArray(errors)) {
            return errors as JsonApiError[];
        }
    }

    return [];
}
