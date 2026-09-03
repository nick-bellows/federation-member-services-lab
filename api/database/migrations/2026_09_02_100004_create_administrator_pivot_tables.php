<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administrative roles above the club level. Upstream's spatie roles are
 * scoped to a club (teams key club_id) and cannot express "administers this
 * organization" or "administers the federation"; explicit pivots can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_administrators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('federation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['federation_id', 'user_id']);
        });

        Schema::create('organization_administrators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Named explicitly: Laravel's generated name would be 65 characters,
            // one over MariaDB's 64-character identifier limit (SQLite has none).
            $table->unique(['member_organization_id', 'user_id'], 'organization_administrators_org_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_administrators');
        Schema::dropIfExists('federation_administrators');
    }
};
