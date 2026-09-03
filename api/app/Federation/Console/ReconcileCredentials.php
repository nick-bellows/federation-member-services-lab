<?php

namespace App\Federation\Console;

use App\Federation\Enums\ApplicationStatus;
use App\Federation\LearningCenter\CredentialSnapshots;
use App\Federation\LearningCenter\Exceptions\LearningCenterException;
use App\Federation\Models\CredentialSnapshot;
use App\Federation\Models\RegistrationApplication;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The backstop for everything the synchronous path missed: refresh the
 * snapshot of every applicant with an approved application whose snapshot is
 * missing or older than the limit, and report what changed.
 */
final class ReconcileCredentials extends Command
{
    protected $signature = 'federation:reconcile-credentials
                            {--older-than= : Refresh snapshots older than this many minutes (default: the configured TTL)}
                            {--all : Refresh every applicant with an approved application regardless of age}';

    protected $description = 'Refresh Learning Center credential snapshots for approved applicants';

    public function handle(CredentialSnapshots $snapshots): int
    {
        $minutes = $this->option('older-than') !== null
            ? (int) $this->option('older-than')
            : (int) config('learning_center.snapshot_ttl_minutes');
        $cutoff = now()->subMinutes($minutes);

        $userIds = RegistrationApplication::query()
            ->where('status', ApplicationStatus::APPROVED->value)
            ->distinct()
            ->pluck('applicant_user_id');

        $refreshed = 0;
        $changed = 0;
        $unavailable = 0;
        $skipped = 0;

        foreach (User::query()->withoutGlobalScopes()->whereIn('id', $userIds)->orderBy('id')->cursor() as $user) {
            $snapshot = CredentialSnapshot::query()->where('user_id', $user->getKey())->first();

            if (! $this->option('all') && $snapshot !== null && $snapshot->fetched_at->isAfter($cutoff)) {
                $skipped++;

                continue;
            }

            try {
                $result = $snapshots->refresh($user);
                $refreshed++;
                if ($result->changed) {
                    $changed++;
                    $this->line(sprintf('user %d: eligibility changed to %s', $user->getKey(), $result->snapshot->eligibility_status));
                }
            } catch (LearningCenterException $e) {
                $unavailable++;
                $this->warn(sprintf('user %d: %s', $user->getKey(), $e::class));
            }
        }

        $this->info(sprintf(
            'refreshed=%d changed=%d unavailable=%d skipped=%d (cutoff %s)',
            $refreshed,
            $changed,
            $unavailable,
            $skipped,
            $cutoff->toIso8601String(),
        ));

        return $unavailable > 0 ? self::FAILURE : self::SUCCESS;
    }
}
