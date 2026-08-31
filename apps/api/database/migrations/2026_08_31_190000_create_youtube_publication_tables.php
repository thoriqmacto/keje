<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publication history, and the replacement workflow that produces it.
 *
 * YouTube cannot swap the video file behind an existing id. Correcting
 * anything encoded into the MP4 therefore means a *new* video and a new URL,
 * and the old one has to be disposed of deliberately. That is a multi-step
 * remote operation with real money on both ends of it — a duplicate publication
 * on one side, a permanently deleted lecture on the other — so it cannot live
 * in job memory. It gets tables.
 *
 * Two of them, because they answer different questions:
 *
 *   youtube_publications  what Keje has put on YouTube for this project, ever.
 *                         Append-only history. The current video is a row,
 *                         not a special case.
 *
 *   youtube_replacements  one in-flight correction: which video is being
 *                         replaced, which one replaces it, and exactly how far
 *                         the sequence got. A worker that dies mid-way is
 *                         resumed from these columns, never from a retry's
 *                         assumption about where it was.
 *
 * The existing youtube_* columns on content_projects are deliberately left
 * alone and keep meaning "the current video". Every read path in the app and
 * every historical project goes on working unchanged; the publication rows are
 * additive history layered underneath. Migrating those columns into the new
 * table would have touched every controller, resource and test that reads them
 * to buy nothing this sprint needs.
 *
 * Guarded per statement: MariaDB commits each DDL immediately and cannot roll
 * a failed migration back, so this has to be safe to re-run over a partial
 * application.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('youtube_publications')) {
            Schema::create('youtube_publications', function (Blueprint $table): void {
                $table->id();
                $table->uuid()->unique();

                $table->foreignId('content_project_id')->constrained()->cascadeOnDelete();

                // Which render produced the file behind this video. This is
                // the whole basis for "the video on YouTube is an older
                // render" — a timestamp comparison cannot answer that,
                // because saving a project without changing a render input
                // moves updated_at and changes nothing about the frames.
                $table->foreignId('render_job_id')->nullable()->constrained()->nullOnDelete();
                $table->string('render_input_hash', 64)->nullable();

                $table->string('youtube_video_id', 64)->index();
                $table->string('youtube_url')->nullable();

                // Snapshots, not a live mirror. What this video was published
                // as, so history stays readable after the video is gone from
                // YouTube and videos.list can no longer answer for it.
                $table->string('title')->nullable();
                $table->string('privacy_status', 32)->nullable();
                $table->timestamp('publish_at')->nullable();

                // Lifecycle. became_current_at is the moment this row started
                // representing the project publicly; replaced_at the moment it
                // stopped. Both null on a replacement that is still uploading.
                $table->timestamp('uploaded_at')->nullable();
                $table->timestamp('became_current_at')->nullable();
                $table->timestamp('replaced_at')->nullable();

                // Named for what it means — gone from YouTube — rather than
                // deleted_at, which Eloquent would read as a soft delete.
                $table->timestamp('remote_deleted_at')->nullable();

                // What happened to this video when it stopped being current.
                $table->string('disposition', 32)->nullable();

                // The publication that superseded this one, so history is a
                // chain rather than a pile sorted by date.
                $table->unsignedBigInteger('replacement_of_id')->nullable()->index();

                $table->string('remote_status', 32)->nullable();
                $table->timestamp('remote_synced_at')->nullable();

                $table->timestamps();

                $table->index(['content_project_id', 'became_current_at']);
            });
        }

        if (! Schema::hasTable('youtube_replacements')) {
            Schema::create('youtube_replacements', function (Blueprint $table): void {
                $table->id();
                $table->uuid()->unique();

                $table->foreignId('content_project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();

                $table->string('status', 32);

                /*
                 * Only one replacement may be in flight per project, and a
                 * transaction alone is a weaker promise than it looks: two
                 * workers on two hosts can both pass a check. This column
                 * carries the project id while the workflow is live and NULL
                 * once it reaches a terminal state, and MariaDB permits any
                 * number of NULLs in a unique index — so the database itself
                 * refuses the second concurrent replacement.
                 *
                 * A failed replacement keeps its key: it is recoverable, and
                 * starting a fresh one on top of a private video that is
                 * already uploaded is how a channel ends up with two copies.
                 */
                $table->unsignedBigInteger('active_key')->nullable()->unique();

                // Derived from the persisted facts below, never trusted from
                // the browser: the old video id comes from the project's own
                // publication state.
                $table->string('old_video_id', 64);
                $table->string('new_video_id', 64)->nullable();

                $table->unsignedBigInteger('old_publication_id')->nullable();
                $table->unsignedBigInteger('new_publication_id')->nullable();

                // The render this replacement is carrying to YouTube. Recorded
                // at the start so a re-render mid-flight cannot silently change
                // what the upload means.
                $table->string('render_input_hash', 64)->nullable();
                $table->foreignId('render_job_id')->nullable()->constrained()->nullOnDelete();

                // 'delete' or 'keep_private'. The consequence differs enough
                // that it is stored per replacement rather than read from
                // config at the moment the worker happens to run.
                $table->string('old_disposition', 32)->default('delete');

                // Fractional upload progress, so the UI can show something
                // during the one slow step.
                $table->float('upload_progress')->default(0);

                $table->text('error')->nullable();
                $table->string('failed_stage', 32)->nullable();
                $table->timestamp('failed_at')->nullable();

                $table->timestamp('started_at')->nullable();
                $table->timestamp('uploaded_at')->nullable();
                $table->timestamp('old_disposed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();

                $table->timestamps();

                $table->index(['content_project_id', 'created_at']);
            });
        }

        Schema::table('content_projects', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_projects', 'youtube_render_input_hash')) {
                // The fingerprint of the render the *current YouTube video*
                // was made from. Compared against the project's current
                // fingerprint, this is what distinguishes "you changed the
                // description" from "you changed what is on screen".
                $table->string('youtube_render_input_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('content_projects', 'current_youtube_publication_id')) {
                /*
                 * Deliberately not a foreign key. youtube_publications already
                 * points at content_projects, and a constraint in both
                 * directions is a circular dependency that makes deleting a
                 * project a two-step dance for no benefit. The pointer is
                 * maintained in one service and read nowhere that would be
                 * damaged by a dangling id.
                 */
                $table->unsignedBigInteger('current_youtube_publication_id')->nullable();
            }

            if (! Schema::hasColumn('content_projects', 'finalized_at')) {
                // Set when the user says they are done correcting this project
                // and its working files may go. See MediaRetention.
                $table->timestamp('finalized_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_projects', function (Blueprint $table): void {
            $table->dropColumn([
                'youtube_render_input_hash',
                'current_youtube_publication_id',
                'finalized_at',
            ]);
        });

        Schema::dropIfExists('youtube_replacements');
        Schema::dropIfExists('youtube_publications');
    }
};
