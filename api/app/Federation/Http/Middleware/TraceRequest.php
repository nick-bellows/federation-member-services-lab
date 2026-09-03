<?php

namespace App\Federation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * One server span per federation request and the shared log context every
 * line in the request carries: request id, trace id (via the processor),
 * the acting user's id. Never a token, never an e-mail.
 */
final class TraceRequest
{
    public function __construct(private readonly TracerInterface $tracer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->uri() ?? $request->path();
        $span = $this->tracer->spanBuilder($request->method().' '.$route)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->method())
            ->setAttribute(TraceAttributes::HTTP_ROUTE, $route)
            ->setAttribute('federation.request_id', AssignRequestId::current($request))
            ->startSpan();
        $scope = $span->activate();

        Log::shareContext(array_filter([
            'request_id' => AssignRequestId::current($request),
            'user_id' => $request->user()?->getKey(),
        ]));

        $started = hrtime(true);
        $status = 500;

        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $status);
            if ($status >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return $response;
        } catch (Throwable $e) {
            $span->recordException($e)->setStatus(StatusCode::STATUS_ERROR);
            throw $e;
        } finally {
            // One access line per request, with the shared context still attached:
            // the line every operator greps for first (docs/OBSERVABILITY.md).
            Log::info('request', [
                'method' => $request->method(),
                'route' => $route,
                'status' => $status,
                'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            ]);
            $scope->detach();
            $span->end();
            Log::flushSharedContext();
        }
    }

    public static function currentContext(): Context
    {
        return Context::getCurrent();
    }
}
