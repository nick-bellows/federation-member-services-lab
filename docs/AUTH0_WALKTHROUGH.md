# Auth0 walkthrough

Status: **planned**. The code path exists and is exercised in CI against the self-hosted mock provider (ADR-0007); the Auth0 tenant is the owner's to create (decision 5, deferred to the end of Phase B by the owner on 2026-09-02). Nothing below has been run against a real tenant; the screenshot list says what the record will contain once it has.

## What the code already does

- The web app offers an `Auth0` button on `/member/sign-in` as soon as `AUTH0_ISSUER` and `AUTH0_CLIENT_ID` are set (`web_application/src/lib/federation/providers.ts`). It uses next-auth's Auth0 provider with the authorization-code flow and PKCE, asks for `openid profile email`, and passes `audience` so the access token is minted for the API.
- The access token stays on the server in the encrypted session; the browser holds a cookie (ADR-0007).
- The API validates every bearer token against the issuer's discovery document and key set, then issuer, audience, subject and expiry (`api/config/oidc.php`, `OidcTokenVerifier`), and maps the subject to a user, provisioning one on first sign-in when the e-mail is verified. Capabilities come from the database, never from claims.

## Tenant settings (to be done by the owner)

1. **Create a tenant** on the Auth0 free tier. Note the domain, for example `northgate-dev.eu.auth0.com`.
2. **Create an API** (Applications → APIs): name `Northgate Federation API`, identifier `https://api.northgate.example` (this string is the audience; it does not have to resolve). Signing algorithm RS256. Leave RBAC off: the federation derives capabilities from its own tables.
3. **Create an application** of type Regular Web Application: name `Northgate Member Services`.
   - Allowed Callback URLs: `http://localhost:3000/api/auth/callback/auth0`
   - Allowed Logout URLs: `http://localhost:3000/en/member/sign-in`
   - Allowed Web Origins: `http://localhost:3000`
   - Grant types: Authorization Code, Refresh Token (default). Nothing else.
4. **Enable a connection** for the application: the built-in Username-Password-Authentication database, with e-mail verification on (the API provisions a user only from a verified e-mail).
5. **Create two test users** in that connection, one to act as an applicant and one whose e-mail you will attach to an organization administrator in the seed (`NorthgateDemoSeeder` links administrators by e-mail).

## Environment

Web app (`web_application/.env.local`):

```
AUTH0_ISSUER=https://<tenant>.<region>.auth0.com
AUTH0_CLIENT_ID=<application client id>
AUTH0_CLIENT_SECRET=<application client secret>
OIDC_AUDIENCE=https://api.northgate.example
```

API (`api/.env`), replacing the mock issuer:

```
OIDC_ISSUER=https://<tenant>.<region>.auth0.com/
OIDC_AUDIENCE=https://api.northgate.example
OIDC_JWKS_URI=
```

Note the trailing slash on the API's issuer: Auth0 issues tokens with `iss` ending in `/`, and the verifier compares strings. `OIDC_JWKS_URI` is emptied so the key set comes from the tenant's discovery document. Restart the API container after the change (its configuration is cached at start).

The client secret and the tenant domain are configuration, not documentation: they go into the gitignored environment files and nowhere else.

## The walk to record

| Step | Screenshot | What it proves |
|---|---|---|
| 1 | `/en/member/sign-in` showing both buttons, Northgate ID and Auth0 | the provider list is driven by configuration |
| 2 | Auth0's hosted login page for the tenant | the redirect carries the audience (visible in the URL's `audience` parameter) |
| 3 | `/en/member` after the round trip, showing the identity the API resolved (subject prefixed `auth0|`, e-mail, no scopes for a new person) | the API accepted a token from a second issuer with no code change |
| 4 | The same page for the second user after their e-mail is attached to an organization administrator in the seed, now showing `application:review` | capabilities come from the database, not from Auth0 |
| 5 | A rejected sign-in: the tenant user with an unverified e-mail lands on the 401 explanation | provisioning requires a verified e-mail |
| 6 | The API container's JSON log line `oidc.rejected` for step 5 with its reason, and no token in it | the failure path logs the reason, never the token |

The screenshots go under `docs/assets/auth0/` and this document's status changes to validated with the date. The Playwright journeys keep using the mock provider: CI must not depend on an external account.

## What the mock does not prove and Auth0 will

- Key rotation on a real tenant (the verifier refreshes the key set once on an unknown key id; the mock never rotates).
- The issuer string with its trailing slash.
- Refresh-token behaviour after the access token expires (Auth0's default is 24 hours for an API token; the mock's is one hour).
- Rate limits on the token endpoint for the client-credentials flow, if the Learning Center's provider is also Auth0 one day.
