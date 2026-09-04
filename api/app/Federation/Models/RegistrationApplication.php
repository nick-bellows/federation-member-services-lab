<?php

namespace App\Federation\Models;

use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Enums\DocumentType;
use App\Models\User;
use Database\Factories\Federation\RegistrationApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * A person's request to be registered with a member organization for a role
 * in a season. Its status is a state machine: the only writer is
 * TransitionApplication, through applyTransition(); assigning status anywhere
 * else throws.
 */
class RegistrationApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_organization_id',
        'season_id',
        'registration_window_id',
        'applicant_user_id',
        'role',
        'date_of_birth',
        'phone',
        'applicant_notes',
        'reviewer_notes',
        'idempotency_key',
    ];

    /**
     * True only while applyTransition() is saving. See booted().
     */
    private bool $transitioning = false;

    protected function casts(): array
    {
        return [
            'role' => ApplicationRole::class,
            'status' => ApplicationStatus::class,
            'date_of_birth' => 'date',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // "saving" fires before "creating", so the default status is set here.
        static::saving(function (RegistrationApplication $application): void {
            if (! $application->exists) {
                $application->status ??= ApplicationStatus::DRAFT;
            }

            if ($application->exists && $application->isDirty('status') && ! $application->transitioning) {
                throw new LogicException(
                    'Application status must be changed through TransitionApplication, not assigned directly.'
                );
            }

            $application->active_key = $application->status->isActive()
                ? $application->activeKey()
                : null;
        });
    }

    /**
     * Change status and the accompanying attributes. Called by
     * TransitionApplication only, after the rules have been checked.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function applyTransition(ApplicationStatus $to, array $attributes = []): void
    {
        $this->transitioning = true;

        try {
            $this->forceFill(array_merge($attributes, ['status' => $to]))->save();
        } finally {
            $this->transitioning = false;
        }
    }

    /**
     * Identity of the one live application allowed per person, organization,
     * season and role. Null while the application is rejected or cancelled.
     */
    public function activeKey(): string
    {
        $role = $this->role instanceof ApplicationRole ? $this->role->value : $this->role;

        return implode(':', [
            $this->applicant_user_id,
            $this->member_organization_id,
            $this->season_id,
            $role,
        ]);
    }

    /**
     * Whether the applicant may still change details and documents.
     */
    public function isEditableByApplicant(): bool
    {
        return in_array($this->status, [ApplicationStatus::DRAFT, ApplicationStatus::NEEDS_INFORMATION], true);
    }

    /**
     * Required document types that have no metadata attached yet.
     *
     * @return array<int, DocumentType>
     */
    public function missingRequiredDocuments(): array
    {
        $present = $this->documents()->pluck('document_type')->map(fn ($type) => $type instanceof DocumentType ? $type->value : $type)->all();

        return array_values(array_filter(
            DocumentType::requiredFor($this->role),
            fn (DocumentType $type) => ! in_array($type->value, $present, true),
        ));
    }

    public function memberOrganization(): BelongsTo
    {
        return $this->belongsTo(MemberOrganization::class);
    }

    public function registrationWindow(): BelongsTo
    {
        return $this->belongsTo(RegistrationWindow::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class)->orderBy('document_type');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    public function auditEntries(): MorphMany
    {
        return $this->morphMany(AuditEntry::class, 'auditable')->orderBy('id');
    }

    protected static function newFactory(): RegistrationApplicationFactory
    {
        return RegistrationApplicationFactory::new();
    }
}
