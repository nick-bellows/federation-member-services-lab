<?php

namespace App\Federation\Observability;

use App\Federation\Enums\ApplicationStatus;
use App\Federation\Models\CredentialSnapshot;
use App\Federation\Models\FederationNotification;
use App\Federation\Models\OutboxEvent;
use App\Federation\Models\ProcessedEvent;
use App\Federation\Models\RegistrationApplication;
use Illuminate\Support\Facades\DB;

/**
 * Numbers computed from the federation's own tables on every scrape, in the
 * Prometheus text format. No metrics server is needed for them to be true;
 * a scraper turns them into time series when one exists (ADR-0012).
 */
final class Metrics
{
    public function __construct(private readonly int $snapshotStaleMinutes) {}

    public function render(): string
    {
        $lines = [];
        $gauge = function (string $name, string $help, int|float $value, array $labels = []) use (&$lines): void {
            static $described = [];
            if (! isset($described[$name])) {
                $lines[] = "# HELP {$name} {$help}";
                $lines[] = "# TYPE {$name} gauge";
                $described[$name] = true;
            }
            $labelText = $labels === [] ? '' : '{'.implode(',', array_map(
                static fn (string $k, string $v) => $k.'="'.addcslashes($v, '"\\').'"',
                array_keys($labels),
                $labels,
            )).'}';
            $lines[] = "{$name}{$labelText} {$value}";
        };

        $oldest = OutboxEvent::query()->unpublished()->value('occurred_at');
        $gauge('federation_outbox_unpublished', 'Outbox facts not yet relayed.', OutboxEvent::query()->unpublished()->count());
        $gauge('federation_outbox_oldest_unpublished_seconds', 'Age of the oldest unrelayed fact; a rising value means the worker is not running.', $oldest ? now()->diffInSeconds($oldest, true) : 0);
        $gauge('federation_outbox_parked', 'Facts whose consumer exhausted its retries; replay with federation:outbox-replay.', OutboxEvent::query()->failed()->count());
        $gauge('federation_events_processed', 'Consumer ledger rows (consumer, event) since the tables were created.', ProcessedEvent::query()->count());
        $gauge('federation_jobs_queued', 'Rows in the jobs table.', DB::table('jobs')->count());
        $gauge('federation_jobs_failed', 'Rows in failed_jobs; queue:retry replays them.', DB::table('failed_jobs')->count());

        $cutoff = now()->subMinutes($this->snapshotStaleMinutes);
        $gauge('federation_credential_snapshots', 'Credential snapshots by eligibility status.', 0, ['status' => 'none']);
        foreach (CredentialSnapshot::query()->selectRaw('eligibility_status, count(*) as n')->groupBy('eligibility_status')->pluck('n', 'eligibility_status') as $status => $n) {
            $gauge('federation_credential_snapshots', 'Credential snapshots by eligibility status.', (int) $n, ['status' => (string) $status]);
        }
        $gauge('federation_credential_snapshots_stale', 'Snapshots older than the configured limit; reconciliation refreshes them.', CredentialSnapshot::query()->where('fetched_at', '<', $cutoff)->count());

        $byStatus = RegistrationApplication::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        foreach (ApplicationStatus::cases() as $status) {
            $gauge('federation_applications', 'Registration applications by status.', (int) ($byStatus[$status->value] ?? 0), ['status' => $status->value]);
        }
        $gauge('federation_notifications', 'Notification rows written by the notification consumer.', FederationNotification::query()->count());

        return implode("\n", $lines)."\n";
    }
}
