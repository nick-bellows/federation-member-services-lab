<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reviewer-only field, so that field-level authorization has two sides to
 * show (ADR-0014): applicants patch their own details, reviewers patch this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_applications', function (Blueprint $table) {
            $table->text('reviewer_notes')->nullable()->after('applicant_notes');
        });
    }

    public function down(): void
    {
        Schema::table('registration_applications', function (Blueprint $table) {
            $table->dropColumn('reviewer_notes');
        });
    }
};
