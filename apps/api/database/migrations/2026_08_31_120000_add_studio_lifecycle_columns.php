<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for the editable-project lifecycle.
 *
 * Four separate concerns, deliberately not one JSON blob:
 *
 *   - the render-input fingerprint, which has to be comparable in SQL
 *   - the audio edit list, which is genuinely a variable-length structure
 *   - post-render actions, snapshotted per attempt so a queued render cannot
 *     be changed by editing the project afterwards
 *   - the remote YouTube state, kept apart from our own pipeline status so
 *     "our upload failed" and "Google says it is private" never collapse into
 *     one ambiguous value
 *
 * Written to be re-runnable over a partial application: MySQL and MariaDB
 * commit each DDL statement immediately and cannot roll a failed migration
 * back, so every add is guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_projects', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_projects', 'audio_edits')) {
                // Ordered, non-overlapping removed ranges in decimal seconds.
                // A list, so a table would buy nothing: nothing else ever
                // joins to a single cut.
                $table->json('audio_edits')->nullable()->after('source_audio_bitrate');
            }

            if (! Schema::hasColumn('content_projects', 'last_render_input_hash')) {
                // The fingerprint the current output was produced from.
                // Indexed length is unnecessary — it is only ever compared
                // for equality against one project's own value.
                $table->string('last_render_input_hash', 64)->nullable()->after('output_duration');
            }

            if (! Schema::hasColumn('content_projects', 'youtube_remote_status')) {
                $table->string('youtube_remote_status', 32)->nullable()->after('youtube_publish_at');
                $table->string('youtube_remote_privacy_status', 32)->nullable()->after('youtube_remote_status');
                $table->timestamp('youtube_remote_publish_at')->nullable()->after('youtube_remote_privacy_status');
                $table->timestamp('youtube_remote_synced_at')->nullable()->after('youtube_remote_publish_at');
                $table->text('youtube_remote_sync_error')->nullable()->after('youtube_remote_synced_at');
            }

            if (! Schema::hasColumn('content_projects', 'thumbnail_path')) {
                $table->string('thumbnail_path')->nullable()->after('youtube_remote_sync_error');
                $table->float('thumbnail_timestamp')->nullable()->after('thumbnail_path');
                $table->timestamp('thumbnail_generated_at')->nullable()->after('thumbnail_timestamp');
                $table->string('youtube_thumbnail_status', 32)->nullable()->after('thumbnail_generated_at');
                $table->text('youtube_thumbnail_error')->nullable()->after('youtube_thumbnail_status');
                $table->timestamp('youtube_thumbnail_synced_at')->nullable()->after('youtube_thumbnail_error');
            }
        });

        Schema::table('render_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('render_jobs', 'render_input_hash')) {
                $table->string('render_input_hash', 64)->nullable()->after('progress_percent');
            }

            if (! Schema::hasColumn('render_jobs', 'post_actions')) {
                // What the person asked for when they pressed Render. Stored
                // on the attempt rather than the project so the choice cannot
                // drift while the job sits on the queue.
                $table->json('post_actions')->nullable()->after('render_input_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_projects', function (Blueprint $table): void {
            $table->dropColumn([
                'audio_edits',
                'last_render_input_hash',
                'youtube_remote_status',
                'youtube_remote_privacy_status',
                'youtube_remote_publish_at',
                'youtube_remote_synced_at',
                'youtube_remote_sync_error',
                'thumbnail_path',
                'thumbnail_timestamp',
                'thumbnail_generated_at',
                'youtube_thumbnail_status',
                'youtube_thumbnail_error',
                'youtube_thumbnail_synced_at',
            ]);
        });

        Schema::table('render_jobs', function (Blueprint $table): void {
            $table->dropColumn(['render_input_hash', 'post_actions']);
        });
    }
};
