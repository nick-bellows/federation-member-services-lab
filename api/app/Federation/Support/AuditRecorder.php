<?php

namespace App\Federation\Support;

use App\Federation\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditRecorder
{
    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     */
    public function record(
        ?User $actor,
        string $action,
        Model $auditable,
        ?array $previous = null,
        ?array $new = null,
        ?string $reason = null,
        ?string $requestId = null,
    ): AuditEntry {
        return AuditEntry::create([
            'actor_user_id' => $actor?->getKey(),
            'actor_type' => $actor ? 'user' : 'system',
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'previous_state' => $previous,
            'new_state' => $new,
            'reason' => $reason,
            'request_id' => $requestId,
            'occurred_at' => now(),
        ]);
    }
}
