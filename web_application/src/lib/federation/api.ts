import 'server-only';

export interface FederationIdentity {
    id: string;
    name: string;
    email: string;
    issuer: string | null;
    subject: string | null;
    scopes: string[];
    administeredFederations: { id: string; code: string; name: string }[];
    administeredMemberOrganizations: {
        id: string;
        code: string;
        name: string;
        federationId: string;
    }[];
}

export class FederationApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
    ) {
        super(message);
        this.name = 'FederationApiError';
    }
}

/**
 * Federation (fork): server-side client for /api/v1/federation. Sends the
 * OIDC access token as a bearer credential; the API validates it itself.
 */
export async function fetchFederationIdentity(
    accessToken: string,
): Promise<FederationIdentity> {
    const response = await fetch(
        `${process.env.API_DOMAIN}${process.env.API_PATH}/federation-identity/me`,
        {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${accessToken}`,
            },
            cache: 'no-store',
        },
    );

    if (!response.ok) {
        throw new FederationApiError(
            response.status,
            `Federation API responded with ${response.status}`,
        );
    }

    const body = (await response.json()) as {
        data: { id: string; attributes: Omit<FederationIdentity, 'id'> };
    };

    return { id: body.data.id, ...body.data.attributes };
}
