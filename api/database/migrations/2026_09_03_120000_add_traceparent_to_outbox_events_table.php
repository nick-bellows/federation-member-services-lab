<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The W3C trace context of the request that wrote the fact, so the worker's
 * span continues the same trace (ADR-0012).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->string('traceparent', 64)->nullable()->after('request_id');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropColumn('traceparent');
        });
    }
};
