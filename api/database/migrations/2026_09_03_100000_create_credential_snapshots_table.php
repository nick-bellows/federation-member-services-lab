<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last answer the Learning Center gave about a person, kept so that
 * participation can be derived on read without a live call (ADR-0009).
 * One row per user; the payload is the contract response verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->string('contract', 64);
            $table->string('eligibility_status', 32);
            $table->json('payload')->nullable();
            $table->timestamp('source_as_of')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_snapshots');
    }
};
