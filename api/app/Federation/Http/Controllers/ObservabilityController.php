<?php

namespace App\Federation\Http\Controllers;

use App\Federation\Observability\Metrics;
use App\Federation\Observability\Readiness;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\Health\ResultStores\ResultStore;

/**
 * Probes and metrics (ADR-0012). Liveness answers without touching anything;
 * readiness asks the dependencies; checks exposes upstream's spatie/health
 * results, which `php artisan health:check` refreshes; metrics renders the
 * federation's numbers in the Prometheus text format.
 */
final class ObservabilityController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'time' => now()->toIso8601String()]);
    }

    public function ready(Readiness $readiness): JsonResponse
    {
        $result = $readiness->evaluate();

        return response()->json([
            'status' => $result['ready'] ? 'ready' : 'not_ready',
            'checks' => $result['checks'],
            'time' => now()->toIso8601String(),
        ], $result['ready'] ? 200 : 503);
    }

    public function checks(ResultStore $store): JsonResponse
    {
        $results = $store->latestResults();

        if ($results === null) {
            return response()->json([
                'status' => 'unknown',
                'detail' => 'No stored results yet; run `php artisan health:check` (scheduled in production).',
            ], 200);
        }

        $failed = $results->containsFailingCheck();

        return response()->json([
            'status' => $failed ? 'failing' : 'ok',
            'finished_at' => $results->finishedAt->format(DATE_ATOM),
            'checks' => array_values(array_map(static fn ($check) => [
                'name' => $check->name,
                'label' => $check->label,
                'status' => $check->status,
                'summary' => $check->shortSummary,
                'notification' => $check->notificationMessage,
            ], $results->storedCheckResults->all())),
        ], $failed ? 503 : 200);
    }

    public function metrics(Request $request, Metrics $metrics): Response
    {
        $token = (string) config('observability.metrics.token');
        if ($token !== '' && ! hash_equals($token, (string) $request->bearerToken())) {
            return response('unauthorized', 401, ['Content-Type' => 'text/plain']);
        }

        return response($metrics->render(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }
}
