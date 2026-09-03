<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The only change to an upstream table in the federation domain milestone:
 * a club may belong to one member organization. Nullable, so every existing
 * club and every upstream workflow keeps working without an assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->foreignId('member_organization_id')
                ->nullable()
                ->after('tax_account_chart_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_organization_id');
        });
    }
};
