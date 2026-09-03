<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a user to an OpenID Connect identity: the issuer that authenticated
 * them and the subject it assigned. Nullable: upstream users keep signing in
 * with passwords and Sanctum tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('oidc_issuer')->nullable()->after('remember_token');
            $table->string('oidc_subject')->nullable()->after('oidc_issuer');

            $table->unique(['oidc_issuer', 'oidc_subject'], 'users_oidc_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_oidc_identity_unique');
            $table->dropColumn(['oidc_issuer', 'oidc_subject']);
        });
    }
};
