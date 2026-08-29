<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One render attempt. Retrying creates a new row rather than mutating the old
 * one, so failed attempts keep their FFmpeg diagnostics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('render_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('content_project_id')->constrained()->cascadeOnDelete();

            $table->string('status')->default('queued');
            $table->unsignedTinyInteger('progress_percent')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->string('output_path')->nullable();
            $table->unsignedBigInteger('output_size')->nullable();
            $table->float('output_duration')->nullable();

            $table->integer('ffmpeg_exit_code')->nullable();
            // Truncated tail only — see FfmpegService::LOG_TAIL_BYTES.
            $table->text('ffmpeg_log')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['content_project_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_jobs');
    }
};
