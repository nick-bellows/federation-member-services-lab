<?php

namespace App\Federation\Models;

use App\Federation\Enums\DocumentReviewStatus;
use App\Federation\Enums\DocumentType;
use App\Models\User;
use Database\Factories\Federation\ApplicationDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata about one document on an application. Written by
 * AttachDocumentMetadata and ReviewDocument only.
 */
class ApplicationDocument extends Model
{
    use HasFactory;

    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    public const ALLOWED_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    protected $fillable = [
        'registration_application_id',
        'document_type',
        'file_name',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'review_status',
        'review_note',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'review_status' => DocumentReviewStatus::class,
            'size_bytes' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RegistrationApplication::class, 'registration_application_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    protected static function newFactory(): ApplicationDocumentFactory
    {
        return ApplicationDocumentFactory::new();
    }
}
