<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a project's binary media was removed from local storage.
 *
 * Pruning nulls the *_path columns, which is what makes every existing guard
 * behave — no re-render, no local playback, no local preview — but a null path
 * cannot distinguish "never uploaded" from "uploaded, rendered, backed up to
 * Drive and then cleaned up". This column carries that difference, so the API
 * can tell the UI to point at Drive instead of at a file that is gone.
 *
 * The descriptive columns (original name, duration, dimensions, codec) are
 * deliberately left alone: the text side of a project stays queryable and
 * displayable after its bytes are gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_projects', function (Blueprint $table) {
            $table->timestamp('media_pruned_at')->nullable()->after('output_duration');
        });
    }

    public function down(): void
    {
        Schema::table('content_projects', function (Blueprint $table) {
            $table->dropColumn('media_pruned_at');
        });
    }
};
