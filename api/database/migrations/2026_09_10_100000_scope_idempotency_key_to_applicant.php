<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An idempotency key belongs to the applicant who presented it (ADR-0016).
 * The unique constraint moves from the key alone to (applicant, key), so two
 * people may present the same key without one receiving the other's
 * application. Idempotent on the three engines: each step checks first.
 */
return new class extends Migration
{
    private const TABLE = 'registration_applications';

    private const OLD_INDEX = 'registration_applications_idempotency_key_unique';

    private const NEW_INDEX = 'registration_applications_applicant_idempotency_unique';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            if (Schema::hasIndex(self::TABLE, self::OLD_INDEX)) {
                $table->dropUnique(self::OLD_INDEX);
            }

            if (! Schema::hasIndex(self::TABLE, self::NEW_INDEX)) {
                $table->unique(['applicant_user_id', 'idempotency_key'], self::NEW_INDEX);
            }
        });
    }

    /**
     * Restores the global constraint. It fails, by design, while two
     * applicants share a key: that data is what the new rule allows and the
     * old one did not, and a rollback must not silently rewrite it.
     */
    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            if (Schema::hasIndex(self::TABLE, self::NEW_INDEX)) {
                $table->dropUnique(self::NEW_INDEX);
            }

            if (! Schema::hasIndex(self::TABLE, self::OLD_INDEX)) {
                $table->unique('idempotency_key', self::OLD_INDEX);
            }
        });
    }
};
