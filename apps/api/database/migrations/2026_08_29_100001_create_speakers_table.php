<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reusable speaker ("Syafiq Riza Basalamah"). The natural-case name is
 * authoritative; templates may uppercase it for rendering only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speakers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // Optional override for what the video actually shows.
            $table->string('display_name')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speakers');
    }
};
