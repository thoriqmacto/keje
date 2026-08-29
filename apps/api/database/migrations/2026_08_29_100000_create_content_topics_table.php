<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lecture series / theme ("Riyadhush Shalihin"). Conceptually maps 1:1 to a
 * YouTube playlist, but playlist linking is optional and never blocks a render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_topics', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            // Set once the topic is linked to (or creates) a YouTube playlist.
            $table->string('youtube_playlist_id')->nullable();

            $table->timestamps();

            // Slugs only need to be unique per owner, not globally.
            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_topics');
    }
};
