<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_applications', function (Blueprint $table) {
            // Nullable so applications created before windows existed stay valid;
            // StartApplication always sets it.
            $table->foreignId('registration_window_id')
                ->nullable()
                ->after('season_id')
                ->constrained()
                ->restrictOnDelete();
            $table->date('date_of_birth')->nullable()->after('role');
            $table->string('phone', 32)->nullable()->after('date_of_birth');
            $table->text('applicant_notes')->nullable()->after('phone');
            // Idempotency for HTTP transitions: a retried request with the same
            // key returns the current state instead of failing or repeating.
            $table->string('transition_idempotency_key', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('registration_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registration_window_id');
            $table->dropColumn(['date_of_birth', 'phone', 'applicant_notes', 'transition_idempotency_key']);
        });
    }
};
