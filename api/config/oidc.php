<?php

/*
|--------------------------------------------------------------------------
| OpenID Connect identity boundary (fork)
|--------------------------------------------------------------------------
|
| Federation routes authenticate with a bearer access token issued by an
| OIDC provider. Laravel validates the token itself (signature against the
| issuer's JWKS, issuer, audience, expiry) and maps the subject to a user.
| Authorization never comes from token claims; it is resolved from the
| database (see App\Federation\Auth\FederationScopes).
|
| Auth0:            OIDC_ISSUER=https://<tenant>.<region>.auth0.com/
|                   OIDC_AUDIENCE=https://api.northgate.example
| Compose / CI:     OIDC_ISSUER=http://host.docker.internal:3004/default
|                   OIDC_JWKS_URI=http://oidc:8080/default/jwks
|                   (the issuer string is what the browser saw; the JWKS is
|                   fetched over the compose network)
|
*/

return [

    'issuer' => env('OIDC_ISSUER'),

    'audience' => env('OIDC_AUDIENCE'),

    // Optional overrides. Defaults: {issuer}/.well-known/openid-configuration, and the jwks_uri it advertises.
    'discovery_url' => env('OIDC_DISCOVERY_URL'),

    'jwks_uri' => env('OIDC_JWKS_URI'),

    'algorithms' => ['RS256'],

    // Seconds of clock skew tolerated for exp / nbf / iat.
    'leeway' => (int) env('OIDC_LEEWAY', 30),

    // Seconds to cache the discovery document and the key set.
    'cache_ttl' => (int) env('OIDC_CACHE_TTL', 3600),

    'http_timeout' => (int) env('OIDC_HTTP_TIMEOUT', 5),

    // Create a users row on first sign-in when the subject is unknown and the
    // token carries a verified e-mail address.
    'provision_users' => (bool) env('OIDC_PROVISION_USERS', true),

];
