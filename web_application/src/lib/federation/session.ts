import 'server-only';

import { getToken } from 'next-auth/jwt';
import { cookies } from 'next/headers';

/**
 * Federation (fork): read the OIDC access token from the encrypted next-auth
 * cookie on the server. This is the only place the token is available; it
 * is never part of the client-visible session.
 */
export async function getOidcAccessToken(): Promise<string | null> {
    const store = cookies();
    const cookieHeader = store.toString();

    if (!cookieHeader) {
        return null;
    }

    // getToken() accepts an API-route style request: a cookies object plus
    // headers. Both shapes are provided so either lookup branch finds the
    // session cookie.
    const token = await getToken({
        req: {
            cookies: Object.fromEntries(
                store.getAll().map((cookie) => [cookie.name, cookie.value]),
            ),
            headers: { cookie: cookieHeader },
        } as never,
        secret: process.env.NEXTAUTH_SECRET,
    });

    if (!token?.oidcAccessToken) {
        return null;
    }

    if (
        token.oidcAccessTokenExpiresAt &&
        token.oidcAccessTokenExpiresAt * 1000 < Date.now()
    ) {
        return null;
    }

    return token.oidcAccessToken;
}
