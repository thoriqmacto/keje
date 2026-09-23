<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the last connection check found.
 *
 * Health has to be stored rather than computed on demand for two reasons.
 * A probe is two Google round trips, which is not something to put in front
 * of every page load — and more importantly, an alert is about a *change*.
 * "The YouTube connection stopped working" can only be noticed by something
 * that remembers it was working an hour ago.
 *
 * `health_failing_since` is the one that earns its keep in a message: it
 * turns "broken" into "broken since Tuesday", which is the difference between
 * a notice somebody skims and one they act on. `health_alerted_at` is what
 * stops the hourly check from sending the same warning twenty-four times a
 * day.
 *
 * Guidance is stored alongside the status rather than re-derived on read.
 * The classifier's advice is specific to what actually went wrong, and a row
 * saying `renew_required` cannot reconstruct whether that came from a revoked
 * grant or a seven-day Testing expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_connections', function (Blueprint $table): void {
            // Guarded, like every add here: MariaDB commits each DDL statement
            // as it runs and cannot roll a failed migration back, so this has
            // to be safe to re-run over a half-applied state.
            if (! Schema::hasColumn('google_connections', 'health_status')) {
                $table->string('health_status', 32)->nullable()->after('connected_at');
            }

            if (! Schema::hasColumn('google_connections', 'health_message')) {
                $table->string('health_message', 500)->nullable()->after('health_status');
            }

            if (! Schema::hasColumn('google_connections', 'health_guidance')) {
                $table->text('health_guidance')->nullable()->after('health_message');
            }

            if (! Schema::hasColumn('google_connections', 'health_checked_at')) {
                $table->timestamp('health_checked_at')->nullable()->after('health_guidance');
            }

            // When the trouble started, not when it was last seen.
            if (! Schema::hasColumn('google_connections', 'health_failing_since')) {
                $table->timestamp('health_failing_since')->nullable()->after('health_checked_at');
            }

            // When somebody was last told, so an hourly check does not become
            // an hourly email.
            if (! Schema::hasColumn('google_connections', 'health_alerted_at')) {
                $table->timestamp('health_alerted_at')->nullable()->after('health_failing_since');
            }

            // What was last alerted about, so a connection that degrades from
            // "expiring soon" to "dead" says so instead of staying quiet
            // because it already sent something.
            if (! Schema::hasColumn('google_connections', 'health_alerted_status')) {
                $table->string('health_alerted_status', 32)->nullable()->after('health_alerted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('google_connections', function (Blueprint $table): void {
            foreach ([
                'health_status', 'health_message', 'health_guidance', 'health_checked_at',
                'health_failing_since', 'health_alerted_at', 'health_alerted_status',
            ] as $column) {
                if (Schema::hasColumn('google_connections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
