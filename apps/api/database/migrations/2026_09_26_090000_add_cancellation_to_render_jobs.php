<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for a cancellation request to live.
 *
 * The request and the act are separated by a process boundary: the click
 * arrives in a web request, and the FFmpeg it has to stop belongs to a queue
 * worker that may be on another host. A column is the simplest thing both can
 * see, and it survives a worker restart — a cancellation held only in memory
 * or in a cache that is not shared would be silently forgotten, leaving an
 * encode running that somebody has already been told is stopping.
 *
 * Polling it costs one indexed read every couple of seconds for the duration
 * of a render, against a row the worker is already writing progress to.
 *
 * Guarded because MariaDB commits DDL immediately and cannot roll it back, so
 * a re-run of a partly-applied migration has to be a no-op rather than an
 * error.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('render_jobs', 'cancel_requested_at')) {
            Schema::table('render_jobs', function (Blueprint $table) {
                $table->timestamp('cancel_requested_at')->nullable()->after('finished_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('render_jobs', 'cancel_requested_at')) {
            Schema::table('render_jobs', function (Blueprint $table) {
                $table->dropColumn('cancel_requested_at');
            });
        }
    }
};
