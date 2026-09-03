<?php

namespace App\Federation;

use App\Federation\Auth\OidcException;
use App\Federation\Auth\OidcIdentity;
use App\Federation\Auth\OidcTokenVerifier;
use App\Federation\Auth\OidcUserResolver;
use App\Federation\Console\GenerateFederationOpenApi;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            $this->commands([GenerateFederationOpenApi::class]);
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
    }
}
