<?php

namespace App\Federation\Auth;

use RuntimeException;

/**
 * Any reason a bearer token or the identity behind it is not accepted. The
 * message is for logs; callers answer the client with a generic 401.
 */
class OidcException extends RuntimeException
{
    /**
     * True when the token's key id is not in the cached key set, which is the
     * one failure worth retrying with a fresh key set (key rotation).
     */
    public bool $isUnknownKey = false;
}
