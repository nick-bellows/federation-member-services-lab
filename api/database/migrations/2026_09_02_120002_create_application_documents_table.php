<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata about a document an applicant provides: type, name, size,
 * checksum, review status. No file bytes are stored in this milestone; object
 * storage with signed URLs is a later decision (ADR-0008).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_application_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 32);
            $table->string('file_name');
            $table->string('mime_type', 127);
            $table->unsignedInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->string('review_status', 16);
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['registration_application_id', 'document_type'], 'application_documents_app_type_unique');
            $table->index('review_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_documents');
    }
};
