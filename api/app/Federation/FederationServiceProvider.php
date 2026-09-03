<?php

namespace App\Federation;

use App\Federation\Auth\OidcException;
use App\Federation\Auth\OidcIdentity;
use App\Federation\Auth\OidcTokenVerifier;
use App\Federation\Auth\OidcUserResolver;
use App\Federation\Console\GenerateFederationOpenApi;
use App\Federation\Console\ReconcileCredentials;
use App\Federation\Events\ApplicationTransitioned;
use App\Federation\LearningCenter\CredentialsClient;
use App\Federation\LearningCenter\CredentialSnapshots;
use App\Federation\LearningCenter\HttpCredentialsClient;
use App\Federation\LearningCenter\ParticipationResolver;
use App\Federation\LearningCenter\ServiceTokenProvider;
use App\Federation\Listeners\RefreshCredentialsOnApproval;
use App\Federation\Models\ApplicationDocument;
use App\Federation\Models\Federation;
use App\Federation\Models\MemberOrganization;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\RegistrationWindow;
use App\Federation\Models\Season;
use App\Federation\Policies\ApplicationDocumentPolicy;
use App\Federation\Policies\ReadOnlyPolicy;
use App\Federation\Policies\RegistrationApplicationPolicy;
use App\Federation\Policies\RegistrationWindowPolicy;
use App\Federation\Support\AuditRecorder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the federation module into the application: the "oidc" guard and
 * the federation routes. Registered in config/app.php.
 */
class FederationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateFederationOpenApi::class, ReconcileCredentials::class]);
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
                (string) $app['config']->get('learning_center.contract'),
            );
        });

        $this->app->bind(ParticipationResolver::class, function ($app) {
            return new ParticipationResolver(
                (string) $app['config']->get('learning_center.contract'),
                (int) $app['config']->get('learning_center.snapshot_ttl_minutes'),
            );
        });
    }

    public function boot(): void
    {
        // A request guard: Laravel re-injects the current request on every call,
        // so one guard instance serves many requests (the same mechanism Sanctum uses).
        Event::listen(ApplicationTransitioned::class, RefreshCredentialsOnApproval::class);

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
    }
}
