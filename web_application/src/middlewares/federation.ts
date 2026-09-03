import { NextRequestWithAuth } from 'next-auth/middleware';
import { NextURL } from 'next/dist/server/web/next-url';
import { NextResponse } from 'next/server';

const SIGN_IN_PATH = '/member/sign-in';

/**
 * Federation (fork): /member pages require an OIDC session. The sign-in page
 * itself is public. Upstream's /admin handling is untouched.
 */
export function handleMemberPaths(
    request: NextRequestWithAuth,
    nextUrl: NextURL,
) {
    if (nextUrl.pathname.includes(SIGN_IN_PATH)) {
        return NextResponse.next();
    }

    const token = request.nextauth.token;
    const signedInWithOidc =
        token !== null && typeof token.oidcAccessToken === 'string';

    if (signedInWithOidc) {
        return NextResponse.next();
    }

    const locale = nextUrl.pathname.split('/')[1];
    nextUrl.pathname = `/${locale}${SIGN_IN_PATH}`;

    return NextResponse.redirect(nextUrl);
}
