<?php

namespace App\Federation\Observability;

use App\Federation\Models\OutboxEvent;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "May I send traffic here?" Required: the database answers and the outbox is
 * not backing up (the relay is alive). Reported, never required: the Learning
 * Center, because every page answers from stored snapshots without it
 * (ADR-0009). Liveness is a separate, cheaper question answered elsewhere.
 */
final class Readiness
{
    /**
     * @param  array{outbox_max_age_seconds: int, learning_center_timeout_ms: int}  $config
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $learningCenterBaseUrl,
        private readonly array $config,
    ) {}

    /**
     * @return array{ready: bool, checks: array<string, array{status: string, required: bool, detail?: string}>}
     */
    public function evaluate(): array
    {
        $checks = [
            'database' => $this->database(),
            'outbox' => $this->outbox(),
            'learning_center' => $this->learningCenter(),
        ];

        $ready = true;
        foreach ($checks as $check) {
            if ($check['required'] && $check['status'] !== 'ok') {
                $ready = false;
            }
        }

        return ['ready' => $ready, 'checks' => $checks];
    }

    /**
     * @return array{status: string, required: bool, detail?: string}
     */
    private function database(): array
    {
        try {
            /** @var ConnectionInterface $connection */
            $connection = DB::connection();
            $connection->select('select 1');

            return ['status' => 'ok', 'required' => true];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'required' => true, 'detail' => $e::class];
        }
    }

    /**
     * @return array{status: string, required: bool, detail?: string}
     */
    private function outbox(): array
    {
        try {
            $oldest = OutboxEvent::query()->unpublished()->value('occurred_at');
            $age = $oldest ? now()->diffInSeconds($oldest, true) : 0;
            $failed = OutboxEvent::query()->failed()->count();

            if ($age > $this->config['outbox_max_age_seconds']) {
                return ['status' => 'failed', 'required' => true, 'detail' => "oldest unpublished fact is {$age}s old; is the worker running?"];
            }

            return ['status' => 'ok', 'required' => true, 'detail' => "oldest unpublished {$age}s, parked {$failed}"];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'required' => true, 'detail' => $e::class];
        }
    }

    /**
     * @return array{status: string, required: bool, detail?: string}
     */
    private function learningCenter(): array
    {
        try {
            $response = $this->http
                ->connectTimeout($this->config['learning_center_timeout_ms'] / 1000)
                ->timeout($this->config['learning_center_timeout_ms'] / 1000)
                ->get(rtrim($this->learningCenterBaseUrl, '/').'/health');

            return $response->successful()
                ? ['status' => 'ok', 'required' => false]
                : ['status' => 'degraded', 'required' => false, 'detail' => 'answered '.$response->status()];
        } catch (Throwable $e) {
            return ['status' => 'degraded', 'required' => false, 'detail' => 'unreachable within '.$this->config['learning_center_timeout_ms'].' ms'];
        }
    }
}
