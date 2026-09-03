<?php

use App\Federation\Http\Controllers\ApplicationDocumentController;
use App\Federation\Http\Controllers\MeController;
use App\Federation\Http\Controllers\RegistrationApplicationController;
use App\Federation\Http\Controllers\RegistrationWindowController;
use App\Federation\Http\Controllers\ObservabilityController;
use App\Federation\Http\Middleware\AssignRequestId;
use App\Federation\Http\Middleware\TraceRequest;
use Illuminate\Support\Facades\Route;
use LaravelJsonApi\Laravel\Facades\JsonApiRoute;
use LaravelJsonApi\Laravel\Http\Controllers\JsonApiController;
use LaravelJsonApi\Laravel\Routing\ActionRegistrar;

/*
|--------------------------------------------------------------------------
| Federation routes (fork)
|--------------------------------------------------------------------------
|
| Loaded by App\Federation\FederationServiceProvider under the "api" group
| and the /api prefix, like routes/api.php. Everything here authenticates
| with the OIDC bearer guard and carries a correlation id; upstream's
| Sanctum routes are untouched.
|
*/

// Route names must not contain the JSON:API server's name ("federation"):
// the OpenAPI generator treats every such route as a JSON:API action.
Route::middleware(['auth:oidc', AssignRequestId::class, TraceRequest::class])
    ->prefix('v1/federation-identity')
    ->name('identity.')
    ->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me');
    });

JsonApiRoute::server('federation')
    ->prefix('v1/federation')
    ->middleware('auth:oidc', AssignRequestId::class, TraceRequest::class)
    ->resources(function ($server) {
        $server->resource('federations', JsonApiController::class)
            ->only('index', 'show');

        $server->resource('seasons', JsonApiController::class)
            ->only('index', 'show');

        $server->resource('member-organizations', JsonApiController::class)
            ->only('index', 'show');

        $server->resource('registration-windows', RegistrationWindowController::class)
            ->only('index', 'show', 'store', 'update');

        $server->resource('registration-applications', RegistrationApplicationController::class)
            ->only('index', 'show', 'store', 'update')
            ->actions('-actions', function (ActionRegistrar $actions) {
                $actions->withId()->post('submit');
                $actions->withId()->post('cancel');
                $actions->withId()->post('start-review');
                $actions->withId()->post('request-information');
                $actions->withId()->post('approve');
                $actions->withId()->post('reject');
                $actions->withId()->post('refresh-credentials');
            });

        $server->resource('application-documents', ApplicationDocumentController::class)
            ->only('index', 'show', 'store', 'update');
    });

// Probes and metrics (ADR-0012, docs/OBSERVABILITY.md). Unauthenticated by
// design: liveness and readiness are read by the platform, metrics by a
// scraper (optionally with METRICS_TOKEN). None of them expose personal data.
Route::prefix('health')->name('observability.')->group(function () {
    Route::get('live', [ObservabilityController::class, 'live'])->name('live');
    Route::get('ready', [ObservabilityController::class, 'ready'])->name('ready');
    Route::get('checks', [ObservabilityController::class, 'checks'])->name('checks');
});
Route::get('metrics', [ObservabilityController::class, 'metrics'])->name('observability.metrics');
