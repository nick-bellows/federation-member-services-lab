<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('season_id')->constrained()->restrictOnDelete();
            $table->foreignId('applicant_user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 20);
            $table->string('status', 24);
            $table->text('status_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            // Set while the application is open or approved, null once rejected or
            // cancelled: a portable "partial unique index" that lets an applicant
            // apply again after a cancellation without allowing two live applications.
            $table->string('active_key', 96)->nullable()->unique();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['member_organization_id', 'status']);
            $table->index(['applicant_user_id', 'season_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_applications');
    }
};
