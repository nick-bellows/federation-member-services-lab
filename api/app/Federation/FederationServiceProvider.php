<?php

namespace App\Federation;

use App\Federation\Auth\OidcException;
use App\Federation\Auth\OidcIdentity;
use App\Federation\Auth\OidcTokenVerifier;
use App\Federation\Auth\OidcUserResolver;
use App\Federation\Console\FederationWork;
use App\Federation\Console\GenerateFederationOpenApi;
use App\Federation\Console\OutboxRelay;
use App\Federation\Console\OutboxReplay;
use App\Federation\Console\OutboxStatus;
use App\Federation\Console\ReconcileCredentials;
use App\Federation\LearningCenter\CredentialsClient;
use App\Federation\LearningCenter\CredentialSnapshots;
use App\Federation\LearningCenter\HttpCredentialsClient;
use App\Federation\LearningCenter\ParticipationResolver;
use App\Federation\LearningCenter\ServiceTokenProvider;
use App\Federation\Models\ApplicationDocument;
use App\Federation\Models\Federation;
use App\Federation\Models\MemberOrganization;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\RegistrationWindow;
use App\Federation\Models\Season;
use App\Federation\Observability\Metrics;
use App\Federation\Observability\Readiness;
use App\Federation\Observability\Tracing;
use App\Federation\Outbox\OutboxRecorder;
use App\Federation\Policies\ApplicationDocumentPolicy;
use App\Federation\Policies\ReadOnlyPolicy;
use App\Federation\Policies\RegistrationApplicationPolicy;
use App\Federation\Policies\RegistrationWindowPolicy;
use App\Federation\Support\AuditRecorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/**
 * Wires the federation module into the application: the "oidc" guard and
 * the federation routes. Registered in config/app.php.
 */
class FederationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateFederationOpenApi::class,
                ReconcileCredentials::class,
                OutboxRelay::class,
                OutboxStatus::class,
                OutboxReplay::class,
                FederationWork::class,
            ]);
        }

        $this->app->singleton(OidcTokenVerifier::class, function ($app) {
            return new OidcTokenVerifier($app['cache.store'], $app['config']->get('oidc', []));
        });

        $this->app->singleton(OidcUserResolver::class, function ($app) {
            return new OidcUserResolver(
                $app->make(AuditRecorder::class),
                (bool) $app['config']->get('oidc.provision_users', true),
            );
        });

        $this->app->singleton(ServiceTokenProvider::class, function ($app) {
            $config = $app['config']->get('learning_center', []);

            return new ServiceTokenProvider(
                $app->make(HttpFactory::class),
                $app['cache.store'],
                $config['token'],
                (int) $config['timeout_ms'],
            );
        });

        $this->app->bind(CredentialsClient::class, function ($app) {
            $config = $app['config']->get('learning_center', []);

            return new HttpCredentialsClient(
                $app->make(HttpFactory::class),
                $app->make(ServiceTokenProvider::class),
                $app->make(TracerInterface::class),
                (string) $config['base_url'],
                (string) $config['contract'],
                (int) $config['connect_timeout_ms'],
                (int) $config['timeout_ms'],
            );
        });

        $this->app->bind(CredentialSnapshots::class, function ($app) {
            return new CredentialSnapshots(
                $app->make(CredentialsClient::class),
                $app->make(AuditRecorder::class),
                $app->make(OutboxRecorder::class),
                (string) $app['config']->get('learning_center.contract'),
            );
        });

        $this->app->bind(ParticipationResolver::class, function ($app) {
            return new ParticipationResolver(
                (string) $app['config']->get('learning_center.contract'),
                (int) $app['config']->get('learning_center.snapshot_ttl_minutes'),
            );
        });

        $this->app->bind(Readiness::class, function ($app) {
            return new Readiness(
                $app->make(HttpFactory::class),
                (string) $app['config']->get('learning_center.base_url'),
                $app['config']->get('observability.readiness'),
            );
        });
        $this->app->bind(Metrics::class, function ($app) {
            return new Metrics((int) $app['config']->get('observability.metrics.snapshot_stale_minutes'));
        });

        // Tracing (ADR-0012): one provider per process, flushed when the process ends.
        $this->app->singleton(TracerProviderInterface::class, function ($app) {
            return Tracing::provider($app['config']->get('observability.tracing'));
        });
        $this->app->bind(TracerInterface::class, function ($app) {
            return $app->make(TracerProviderInterface::class)->getTracer('federation');
        });
        $this->app->terminating(function () {
            $provider = $this->app->make(TracerProviderInterface::class);
            if (method_exists($provider, 'forceFlush')) {
                $provider->forceFlush();
            }
        });
    }

    public function boot(): void
    {
        // A request guard: Laravel re-injects the current request on every call,
        // so one guard instance serves many requests (the same mechanism Sanctum uses).
        Auth::viaRequest('oidc', function (Request $request) {
            $token = $request->bearerToken();

            if (blank($token)) {
                return null;
            }

            try {
                $identity = $this->app->make(OidcTokenVerifier::class)->verify($token);
                $user = $this->app->make(OidcUserResolver::class)->resolve($identity);
            } catch (OidcException $e) {
                // The reason is for operators; the client only ever sees 401. Never log the token.
                Log::info('oidc.rejected', ['reason' => $e->getMessage()]);

                return null;
            }

            $request->attributes->set(OidcIdentity::class, $identity);

            return $user;
        });

        Gate::policy(Federation::class, ReadOnlyPolicy::class);
        Gate::policy(Season::class, ReadOnlyPolicy::class);
        Gate::policy(MemberOrganization::class, ReadOnlyPolicy::class);
        Gate::policy(RegistrationWindow::class, RegistrationWindowPolicy::class);
        Gate::policy(RegistrationApplication::class, RegistrationApplicationPolicy::class);
        Gate::policy(ApplicationDocument::class, ApplicationDocumentPolicy::class);

        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/federation.php'));

        // The scheduler owns what an operator ran by hand until B8 (ADR-0015):
        // the reconciliation, the outbox status (exit 1 when anything is parked
        // or failed) and upstream's health checks. A failed run writes one JSON
        // line with a stable message, which is where an alarm attaches
        // (docs/DEPLOYMENT.md, docs/RUNBOOK.md).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $failed = static fn (string $task) => static fn () => Log::error('scheduled_task_failed', ['task' => $task]);

            $schedule->command('federation:reconcile-credentials')
                ->hourly()
                ->withoutOverlapping()
                ->onFailure($failed('federation:reconcile-credentials'));

            $schedule->command('federation:outbox-status')
                ->everyFifteenMinutes()
                ->onFailure($failed('federation:outbox-status'));

            $schedule->command('health:check')
                ->everyFifteenMinutes()
                ->withoutOverlapping()
                ->onFailure($failed('health:check'));
        });
    }
}
