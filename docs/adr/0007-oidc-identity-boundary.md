# ADR-0007: An OpenID Connect identity boundary, separate from application authorization

- **Status:** accepted
- **Date:** 2026-09-02
- **Milestone:** M3

## Context

Upstream authenticates club admins with a password against its own users table and issues a one-week Sanctum token with every ability; the public form runs on a super-admin token; Filament uses a session. The federation needs applicants, coaches, referees and administrators to sign in with an identity the federation does not have to store passwords for, and it needs "who are you" kept apart from "what may you do". The roadmap decisions were: identity before the review slice; Auth0 free tier for the walkthrough with a self-hosted provider for compose and CI; a custom guard on `firebase/php-jwt`; next-auth kept as the only session system; the new guard applied to federation routes only.

## Decision

- **Browser:** authorization-code flow with PKCE through next-auth v4 with two providers, `northgate-id` (any OIDC issuer with a discovery document; in compose the `mock-oauth2-server` service) and `auth0` (when a tenant is configured). The Next server exchanges the code; the browser never holds a client secret or an access token. The access token is stored only in the encrypted next-auth JWT cookie and read on the server (`web_application/src/lib/federation/session.ts`); the client-visible session carries the provider name and display fields only.
- **API:** a request guard `oidc` (`App\Federation\FederationServiceProvider`) validates the bearer token itself: RS256 signature against the issuer's JWKS from the discovery document (cached, refreshed once on an unknown key id), then issuer, audience, subject; expiry with leeway. Applied only to `routes/federation.php`. Upstream's `auth:sanctum` routes and Filament are untouched.
- **Identity to user:** `users.oidc_issuer` + `users.oidc_subject`. Unknown subjects link an existing user only through a **verified** e-mail claim, or are provisioned when enabled; an unverified e-mail never links or creates. Both actions write an audit entry.
- **Authorization stays in the database:** `FederationScopes` derives capabilities from the administrator pivots; token `scope` claims are ignored on purpose. Object-level decisions remain with `ApplicationActorResolver` and policies.
- **Tokens for the API carry two audiences:** the web client id and the API identifier, with `azp` set to the client. OpenID Connect allows this; it lets one token satisfy next-auth's ID-token check and Laravel's audience check. Auth0 issues the API audience on the access token when the `audience` parameter is sent, which is the usual configuration.
- **Local provider:** `ghcr.io/navikt/mock-oauth2-server` with personas in `docker/oidc/config.json`; issuer `http://host.docker.internal:3004/default`, a hostname the browser and the containers both resolve.

## Alternatives considered

1. **Auth0's Laravel SDK** — less code for Auth0 alone, but Auth0-specific configuration and awkward against the mock provider in CI.
2. **`@auth0/nextjs-auth0`** — a second session mechanism beside upstream's next-auth.
3. **Exchange the ID token for a Sanctum token at login** — the API would validate OIDC only once, at login, and Sanctum's all-abilities tokens would remain the credential on every request.
4. **Trust roles from token claims** — faster and how many tutorials do it; rejected because a claim proves what the provider knows about the person, not what this application has decided they may do, and because roles would then change only when tokens are reissued.
5. **Keycloak or Dex as the local provider** — realistic, heavier; the mock server exists precisely for tests and lets a spec choose subject and claims at sign-in.

## Consequences

- Positive: no passwords for federation users; upstream flows unchanged; every rejection reason logged without the token; account-takeover through unverified e-mail claims is closed; tests cover signature, issuer, audience, expiry, algorithm confusion, key rotation, provisioning, linking and conflicts without any provider running.
- Negative: two identity systems coexist until upstream's club-admin login is migrated, which is not planned; `host.docker.internal` is a Docker Desktop convention that CI has to reproduce with `extra_hosts`; the ID token's multi-audience shape must be configured on the provider.
- Follow-ups: Auth0 tenant walkthrough with screenshots (owner action); CI end-to-end job; refresh tokens and session expiry handling when the review slice needs longer sessions; token binding to the audit request id.
