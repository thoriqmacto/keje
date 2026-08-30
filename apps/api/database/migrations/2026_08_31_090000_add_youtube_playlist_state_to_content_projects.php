<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the outcome of adding an uploaded video to a playlist.
 *
 * Playlist membership was best-effort and invisible: addToPlaylist() caught
 * everything and returned a bool nobody stored, so a video could upload
 * successfully, silently fail to join its playlist, and look complete.
 *
 * Keeping the upload successful when the playlist step fails is right — the
 * video exists and re-uploading would create a duplicate. Hiding the failure
 * is not. These columns make it reportable and, with them, retryable without
 * touching videos.insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_projects', function (Blueprint $table) {
            // The playlistItem resource, not the video: this is what proves
            // membership and what a retry checks before inserting again.
            $table->string('youtube_playlist_item_id')->nullable()->after('youtube_publish_at');
            $table->string('youtube_playlist_id')->nullable()->after('youtube_playlist_item_id');
            $table->timestamp('youtube_playlist_added_at')->nullable()->after('youtube_playlist_id');
            $table->text('youtube_playlist_error')->nullable()->after('youtube_playlist_added_at');
        });
    }

    public function down(): void
    {
        Schema::table('content_projects', function (Blueprint $table) {
            $table->dropColumn([
                'youtube_playlist_item_id',
                'youtube_playlist_id',
                'youtube_playlist_added_at',
                'youtube_playlist_error',
            ]);
        });
    }
};
