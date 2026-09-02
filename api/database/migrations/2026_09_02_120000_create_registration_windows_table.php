<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization administrator opens registration for a season and a set
 * of roles. Applications can only be started inside an open window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->constrained()->restrictOnDelete();
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->json('roles');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['member_organization_id', 'season_id'], 'registration_windows_org_season_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_windows');
    }
};
