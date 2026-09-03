<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox (ADR-0010): facts written in the same transaction as
 * the state change they describe, delivered later by the relay; the
 * per-consumer ledger that makes at-least-once delivery act once; and the
 * notification rows the notification consumer writes instead of sending mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 64);
            $table->string('aggregate_type', 64);
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload');
            $table->string('request_id', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['published_at', 'id'], 'outbox_events_unpublished_index');
            $table->index(['aggregate_type', 'aggregate_id'], 'outbox_events_aggregate_index');
            $table->index('event_type');
        });

        Schema::create('processed_events', function (Blueprint $table) {
            $table->string('consumer', 64);
            $table->uuid('event_id');
            $table->timestamp('processed_at');

            $table->primary(['consumer', 'event_id']);
        });

        Schema::create('federation_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('template', 64);
            $table->json('payload');
            $table->timestamp('created_at');

            $table->unique(['user_id', 'event_id'], 'federation_notifications_once_per_event');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_notifications');
        Schema::dropIfExists('processed_events');
        Schema::dropIfExists('outbox_events');
    }
};
