<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One produced video: grouping (topic + sequence), speaker, source media,
 * template text, and three independent publication pipelines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // --- Grouping -------------------------------------------------
            $table->foreignId('topic_id')->nullable()
                ->constrained('content_topics')->nullOnDelete();
            $table->unsignedInteger('topic_sequence')->nullable();

            $table->foreignId('speaker_id')->nullable()
                ->constrained('speakers')->nullOnDelete();

            // --- Identity -------------------------------------------------
            $table->string('working_title');
            $table->string('slug');
            $table->string('template_key')->default('kajian-tematik');

            // --- Template text (natural case; templates transform on render)
            $table->string('primary_title')->nullable();
            $table->string('subtitle')->nullable();
            $table->unsignedInteger('part_number')->nullable();

            // --- Independent pipelines ------------------------------------
            $table->string('render_status')->default('draft');
            $table->string('drive_status')->default('pending');
            $table->string('youtube_status')->default('pending');

            // --- Source audio ---------------------------------------------
            $table->string('source_audio_path')->nullable();
            $table->string('source_audio_original_name')->nullable();
            $table->string('source_audio_mime')->nullable();
            $table->unsignedBigInteger('source_audio_size')->nullable();
            $table->float('source_audio_duration')->nullable();
            $table->string('source_audio_codec')->nullable();
            $table->unsignedInteger('source_audio_sample_rate')->nullable();
            $table->unsignedInteger('source_audio_channels')->nullable();
            $table->unsignedBigInteger('source_audio_bitrate')->nullable();

            // --- Background image -----------------------------------------
            $table->string('background_image_path')->nullable();
            $table->string('background_image_original_name')->nullable();
            $table->string('background_image_mime')->nullable();
            $table->unsignedBigInteger('background_image_size')->nullable();
            $table->unsignedInteger('background_image_width')->nullable();
            $table->unsignedInteger('background_image_height')->nullable();

            // --- Render output --------------------------------------------
            $table->string('output_path')->nullable();
            $table->unsignedBigInteger('output_size')->nullable();
            $table->float('output_duration')->nullable();
            $table->timestamp('rendered_at')->nullable();
            $table->text('render_error')->nullable();

            // Per-project render overrides (loudness normalisation, etc.).
            $table->json('render_settings')->nullable();

            // --- Google Drive ---------------------------------------------
            $table->string('drive_file_id')->nullable();
            $table->string('drive_file_name')->nullable();
            $table->string('drive_web_view_link')->nullable();
            $table->timestamp('drive_uploaded_at')->nullable();
            $table->text('drive_error')->nullable();

            // --- YouTube ---------------------------------------------------
            $table->json('youtube_metadata')->nullable();
            $table->string('youtube_video_id')->nullable();
            $table->string('youtube_url')->nullable();
            $table->timestamp('youtube_uploaded_at')->nullable();
            $table->timestamp('youtube_publish_at')->nullable();
            $table->text('youtube_error')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'render_status']);
            $table->index(['user_id', 'drive_status']);
            $table->index(['user_id', 'youtube_status']);
            $table->index(['topic_id', 'topic_sequence']);
            $table->index('speaker_id');
            $table->index('youtube_video_id');
            $table->index('drive_file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_projects');
    }
};
