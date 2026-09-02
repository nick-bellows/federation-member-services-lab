<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('federation_id')->constrained()->cascadeOnDelete();
            $table->string('label', 32);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->unique(['federation_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
