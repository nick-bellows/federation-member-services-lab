import 'next-auth';
import 'next-auth/jwt';

/**
 * Federation (fork): fields added to the next-auth token and session.
 *
 * The OIDC access token lives only in the encrypted JWT cookie and is read
 * server-side (see src/lib/federation/session.ts); it is never copied into
 * the session object that reaches the browser.
 */
declare module 'next-auth/jwt' {
    interface JWT {
        provider?: string;
        oidcAccessToken?: string;
        oidcAccessTokenExpiresAt?: number;
    }
}

declare module 'next-auth' {
    interface Session {
        provider?: string;
        federationUser?: {
            name?: string | null;
            email?: string | null;
        };
    }
}
