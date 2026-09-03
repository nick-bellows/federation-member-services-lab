<?php

namespace Tests\Unit;

use App\Mail\WelcomeClubAdminMailable;
use App\Models\Club;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Providers\HealthCheckServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Spatie\Health\Checks\Checks\PingCheck;
use Spatie\Health\Facades\Health;
use Tests\TestCase;

/**
 * Regression tests: runtime settings must be read through config(), never env().
 *
 * env() only sees the .env file while configuration is not cached. The api
 * container caches configuration on every start (docker/api/entrypoint.sh),
 * after which env() returns null and these call sites fell back to their
 * hard-coded defaults, the upstream production domain. Evidence of the
 * behaviour before the fix: docs/baseline/env_bug_before_fix.txt.
 */
class WebApplicationUrlConfigTest extends TestCase
{
    public function test_club_apply_url_uses_the_configured_web_application_url(): void
    {
        config(['app.web_application_url' => 'https://members.example.test']);

        $club = Club::factory()->make(['slug' => 'northgate-united']);

        $this->assertSame(
            'https://members.example.test/de/northgate-united/apply',
            $club->apply_url,
        );
    }

    public function test_welcome_mail_login_url_and_support_address_come_from_config(): void
    {
        config([
            'app.web_application_url' => 'https://members.example.test',
            'app.club_admin_login_path' => '/de/admin/auth/login',
            'mail.from.address' => 'support@example.test',
        ]);

        $mailable = new WelcomeClubAdminMailable;

        $this->assertSame('https://members.example.test/de/admin/auth/login', $mailable->url);
        $this->assertSame('support@example.test', $mailable->supportEmail);
    }

    public function test_password_reset_link_uses_the_configured_web_application_url(): void
    {
        config(['app.web_application_url' => 'https://members.example.test']);
        app()->setLocale('en');

        (new AppServiceProvider($this->app))->boot();

        $mail = (new ResetPassword('token-123'))->toMail(
            User::factory()->make(['email' => 'reviewer@example.test']),
        );

        $this->assertSame(
            'https://members.example.test/en/admin/auth/reset-password?token=token-123',
            $mail->actionUrl,
        );
    }

    public function test_health_ping_checks_target_the_configured_web_application_url(): void
    {
        config(['app.web_application_url' => 'https://members.example.test']);

        Health::clearChecks();
        (new HealthCheckServiceProvider($this->app))->boot();

        $urls = Health::registeredChecks()
            ->filter(fn ($check) => $check instanceof PingCheck)
            ->map(fn (PingCheck $check) => (fn () => $this->url)->call($check))
            ->values();

        $this->assertCount(2, $urls);
        foreach ($urls as $url) {
            $this->assertStringStartsWith('https://members.example.test/', $url);
        }
    }
}
