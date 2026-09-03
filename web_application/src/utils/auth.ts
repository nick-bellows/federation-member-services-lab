import CredentialsProvider from 'next-auth/providers/credentials';
import type {
    GetServerSidePropsContext,
    NextApiRequest,
    NextApiResponse,
} from 'next';
import type { NextAuthOptions } from 'next-auth';
import { getServerSession } from 'next-auth';
import { AdminApi } from '@/services/admin-api';
import { JsonApiUser } from '@/types/jsonapi-models';
import { cookies } from 'next/headers';
import { federationOidcProviders } from '@/lib/federation/providers';

export const authOptions = {
    providers: [
        // Federation (fork): OpenID Connect providers for the /member pages.
        ...federationOidcProviders(),
        CredentialsProvider({
            name: 'Credentials',
            credentials: {
                email: {
                    type: 'email',
                },
                password: { type: 'password' },
            },

            async authorize(credentials) {
                const data = {
                    email: credentials?.email,
                    password: credentials?.password,
                };

                try {
                    const response = new AdminApi().login(data);

                    return response;
                } catch (error: any) {
                    throw new Error(error);
                }
            },
        }),
    ],
    callbacks: {
        async jwt({ token, user, account }) {
            // Federation (fork): an OIDC sign-in stores the provider and the
            // access token in the encrypted cookie only; upstream's Credentials
            // flow below is unchanged.
            if (account && account.provider !== 'credentials') {
                token.provider = account.provider;
                token.oidcAccessToken = account.access_token;
                token.oidcAccessTokenExpiresAt = account.expires_at;
                token.federationUser = { name: user?.name, email: user?.email };

                return token;
            }

            if (user) {
                token.accessToken = user.meta.token;
                token.club_id = user.meta.club_id;
                token.user = user.data;
            }

            return token;
        },
        async session({ session, token }) {
            if (token.provider && token.provider !== 'credentials') {
                session.provider = token.provider;
                session.federationUser = token.federationUser as {
                    name?: string | null;
                    email?: string | null;
                };

                return session;
            }

            session.accessToken = token.accessToken as string;
            session.user = token.user as JsonApiUser['data'];
            session.club_id = (token.club_id as number).toString();

            return session;
        },
    },
    events: {
        async signOut(message) {
            // deleting the auth token in the api when logout event was triggered
            try {
                if (
                    message.token.provider &&
                    message.token.provider !== 'credentials'
                ) {
                    // Federation (fork): OIDC sessions hold no Sanctum token to revoke.
                } else if (typeof message.token.accessToken === 'string') {
                    await new AdminApi(message.token.accessToken).logout();
                } else {
                    throw new Error('Access token is not a string.');
                }

                const cookieNames = [
                    'next-auth.session-token',
                    'next-auth.callback-url',
                    'next-auth.csrf-token',
                ];

                cookieNames.forEach((cookieName) => {
                    cookies().delete(cookieName);

                    cookies().set(cookieName, '', {
                        expires: new Date(Date.now()),
                        httpOnly: true,
                        sameSite: 'none',
                    });
                });

                return;
            } catch (error: any) {
                throw new Error(error);
            }
        },
    },
    pages: {
        signIn: '/admin/auth/login',
    },
} satisfies NextAuthOptions;

export function auth(
    ...args:
        | [GetServerSidePropsContext['req'], GetServerSidePropsContext['res']]
        | [NextApiRequest, NextApiResponse]
        | []
) {
    return getServerSession(...args, authOptions);
}
