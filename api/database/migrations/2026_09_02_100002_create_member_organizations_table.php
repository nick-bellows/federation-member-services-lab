<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('federation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 16);
            $table->timestamps();

            $table->unique(['federation_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_organizations');
    }
};
