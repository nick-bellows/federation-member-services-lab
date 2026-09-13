import { NextRequestWithAuth } from 'next-auth/middleware';
import { NextURL } from 'next/dist/server/web/next-url';
import { NextResponse } from 'next/server';

const LOGIN_PATHNAME = process.env.ADMIN_LOGIN_PATHNAME ?? '/admin/auth/login';
const PUBLIC_AUTH_PATHS = [
    LOGIN_PATHNAME,
    '/admin/auth/forgot-password',
    '/admin/auth/reset-password',
];

export function handleAdminPaths(
    request: NextRequestWithAuth,
    nextUrl: NextURL,
) {
    const token = request.nextauth.token;

    // Federation (fork): a session from an OIDC provider carries no club-admin
    // token, so for the admin area it counts as not signed in; the member
    // area has its own gate in middlewares/federation.ts.
    const isClubAdminSession =
        token !== null &&
        (token.provider === undefined || token.provider === 'credentials');

    if (!isClubAdminSession) {
        return handleUnauthenticated(request, nextUrl);
    }

    return NextResponse.next();
}

function handleUnauthenticated(request: NextRequestWithAuth, nextUrl: NextURL) {
    const isPublicPath = PUBLIC_AUTH_PATHS.some((path) =>
        nextUrl.pathname.includes(path),
    );

    if (isPublicPath) {
        return NextResponse.next();
    }

    nextUrl.pathname = LOGIN_PATHNAME;

    return NextResponse.redirect(request.nextUrl);
}
