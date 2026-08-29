<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side Google OAuth credentials. Tokens are encrypted at rest via
 * the model's 'encrypted' casts and are never exposed through the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('scopes')->nullable();

            $table->string('google_account_email')->nullable();

            // Resolved after connecting, compared against the expected channel.
            $table->string('youtube_channel_id')->nullable();
            $table->string('youtube_channel_title')->nullable();

            $table->timestamp('connected_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_connections');
    }
};
