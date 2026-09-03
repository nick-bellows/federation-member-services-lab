import type { User } from 'next-auth';
import type { Provider } from 'next-auth/providers/index';
import Auth0Provider from 'next-auth/providers/auth0';

/**
 * Upstream augments next-auth's User with the shape of its own login
 * response (JsonApiUser + meta.token). An OIDC profile has none of that, and
 * the jwt callback never reads it for OIDC sign-ins, so the cast is confined
 * to this one place.
 */
function oidcProfileToUser(profile: {
    sub: string;
    name?: string;
    email?: string;
}): User {
    return {
        id: profile.sub,
        name: profile.name ?? profile.email ?? profile.sub,
        email: profile.email,
    } as unknown as User;
}

export const MOCK_PROVIDER_ID = 'northgate-id';
export const AUTH0_PROVIDER_ID = 'auth0';

/**
 * Federation (fork): the OpenID Connect providers offered on /member/sign-in.
 *
 * Both use the authorization-code flow with PKCE; the browser never sees a
 * client secret. The "audience" parameter asks the provider for an access
 * token meant for the Laravel API, which validates it against OIDC_AUDIENCE.
 *
 * - OIDC_ISSUER: any OIDC provider with a discovery document. In compose this
 *   is the mock-oauth2-server service; its interactive login page lets you
 *   choose the subject and add claims such as email and email_verified.
 * - AUTH0_ISSUER: an Auth0 tenant, when configured.
 */
export function federationOidcProviders(): Provider[] {
    const providers: Provider[] = [];
    const audience = process.env.OIDC_AUDIENCE;

    if (process.env.OIDC_ISSUER) {
        providers.push({
            id: MOCK_PROVIDER_ID,
            name: 'Northgate ID',
            type: 'oauth',
            wellKnown: `${process.env.OIDC_ISSUER}/.well-known/openid-configuration`,
            clientId: process.env.OIDC_CLIENT_ID ?? 'northgate-web',
            clientSecret:
                process.env.OIDC_CLIENT_SECRET ?? 'northgate-web-secret',
            authorization: {
                params: { scope: 'openid profile email', audience },
            },
            idToken: true,
            checks: ['pkce', 'state'],
            profile: oidcProfileToUser,
        });
    }

    if (process.env.AUTH0_ISSUER && process.env.AUTH0_CLIENT_ID) {
        providers.push(
            Auth0Provider({
                id: AUTH0_PROVIDER_ID,
                clientId: process.env.AUTH0_CLIENT_ID,
                clientSecret: process.env.AUTH0_CLIENT_SECRET ?? '',
                issuer: process.env.AUTH0_ISSUER,
                authorization: {
                    params: { scope: 'openid profile email', audience },
                },
            }),
        );
    }

    return providers;
}

export function configuredFederationProviders(): {
    id: string;
    label: string;
}[] {
    const list: { id: string; label: string }[] = [];

    if (process.env.OIDC_ISSUER) {
        list.push({ id: MOCK_PROVIDER_ID, label: 'Northgate ID' });
    }

    if (process.env.AUTH0_ISSUER && process.env.AUTH0_CLIENT_ID) {
        list.push({ id: AUTH0_PROVIDER_ID, label: 'Auth0' });
    }

    return list;
}
