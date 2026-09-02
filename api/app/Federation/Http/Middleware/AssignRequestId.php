<?php

namespace App\Federation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every federation request a correlation id: the caller's X-Request-Id
 * when it is well-formed, otherwise a fresh UUID. The id is echoed in the
 * response and written into audit entries, so a support conversation can go
 * from a screenshot to the exact state change.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public const ATTRIBUTE = 'federation.request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header(self::HEADER, '');

        $requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    public static function current(Request $request): ?string
    {
        $value = $request->attributes->get(self::ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}
