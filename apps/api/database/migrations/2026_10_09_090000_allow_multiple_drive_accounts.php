<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Many Drive accounts per user, instead of one.
 *
 * A free Google account holds fifteen gigabytes, shared with Gmail and
 * Photos. A rendered lecture is a few hundred megabytes. So the ceiling on
 * how much of a course Keje can back up is not a Keje limit at all — it is
 * one account's quota — and the only honest answer to "it is nearly full" is
 * to let another account be added beside it.
 *
 * ── Drive becomes many, YouTube stays one ───────────────────────────────
 *
 * The unique key moves from (user_id, service) to
 * (user_id, service, account_key), which lets a user hold several Drive rows
 * while still allowing exactly one YouTube row.
 *
 * `account_key` exists because the obvious column does not work. Keying on
 * google_account_email would relax the constraint for YouTube as well:
 * MySQL permits duplicate NULLs in a unique index, so two YouTube rows with
 * no email recorded would both be accepted, and the database would stop being
 * the thing that guarantees one channel per user. Uploading a lecture to the
 * wrong channel is not undoable, so that guarantee is worth keeping at the
 * level that cannot be bypassed by a code path somebody adds later.
 *
 * So account_key is NOT NULL with a defined value for every row:
 *
 *   YouTube  ''              — always, so a second row collides.
 *   Drive    the email       — lowercased, so accounts are one per address.
 *   Drive    the row's uuid  — when the email is not known yet, so two
 *                             unidentified accounts do not collide with
 *                             each other.
 *
 * GoogleConnection keeps it in step on every save; nothing assigns it by
 * hand.
 *
 * ── Index order ─────────────────────────────────────────────────────────
 *
 * Same constraint the per-service split hit: user_id carries a foreign key,
 * and InnoDB refuses to drop the last index supporting one. The new composite
 * has user_id leftmost, so it is created first and can take over that duty
 * before the old unique is dropped. The foreign key itself is never touched.
 *
 * Every step is guarded. MariaDB commits DDL immediately and cannot roll it
 * back, so a run that failed halfway leaves its work behind and this has to
 * be safe to re-run over that.
 */
return new class extends Migration
{
    private const TABLE = 'google_connections';

    private const OLD_UNIQUE = 'google_connections_user_id_service_unique';

    private const NEW_UNIQUE = 'google_connections_user_id_service_account_key_unique';

    /** Carries the user_id foreign key once the unique above is dropped. */
    private const FALLBACK_INDEX = 'google_connections_user_id_service_index';

    public function up(): void
    {
        /*
         * A public identifier, so the Drive page can address one account
         * without the API handing out sequential integer ids — the same rule
         * every other model here follows. Nullable first, backfilled, then
         * made unique, because an existing table cannot take a unique NOT
         * NULL column in one step.
         */
        if (! Schema::hasColumn(self::TABLE, 'uuid')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->uuid('uuid')->nullable()->after('id');
            });
        }

        DB::table(self::TABLE)->whereNull('uuid')->orderBy('id')->each(function ($row): void {
            DB::table(self::TABLE)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
        });

        if (! $this->hasIndex('google_connections_uuid_unique')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique('uuid');
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            // Somewhere to say which account this is, in the user's own words.
            // "thoriq.backup2@gmail.com" identifies it; "Archive 2024" is what
            // somebody picking a destination actually recognises.
            if (! Schema::hasColumn(self::TABLE, 'account_label')) {
                $table->string('account_label')->nullable()->after('google_account_email');
            }

            if (! Schema::hasColumn(self::TABLE, 'account_name')) {
                $table->string('account_name')->nullable()->after('account_label');
            }

            /*
             * Fill order. Without it "the first account" is whatever the
             * database happened to return, which would move a backup to a
             * different account between two uploads for no reason anybody
             * could see.
             */
            if (! Schema::hasColumn(self::TABLE, 'priority')) {
                $table->unsignedSmallInteger('priority')->default(0)->after('account_name');
            }

            /*
             * The quota, as Google last reported it. Cached for the same
             * reason the health verdict is: the Drive page shows every
             * account at once, and asking Google live would put one round
             * trip per account in front of every page load.
             *
             * limit is null for an account Google reports as unlimited, which
             * is not the same as zero and must not be read as "full".
             */
            if (! Schema::hasColumn(self::TABLE, 'storage_limit')) {
                $table->unsignedBigInteger('storage_limit')->nullable()->after('priority');
            }

            if (! Schema::hasColumn(self::TABLE, 'storage_usage')) {
                $table->unsignedBigInteger('storage_usage')->nullable()->after('storage_limit');
            }

            if (! Schema::hasColumn(self::TABLE, 'storage_checked_at')) {
                $table->timestamp('storage_checked_at')->nullable()->after('storage_usage');
            }
        });

        if (! Schema::hasColumn(self::TABLE, 'account_key')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                // Empty default rather than nullable: the whole point is a
                // value that participates in a unique index for every row.
                $table->string('account_key', 191)->default('')->after('google_account_email');
            });
        }

        // Existing rows: YouTube keys on '' and Drive on its email, which is
        // exactly what the model will compute from here on.
        DB::table(self::TABLE)->orderBy('id')->each(function ($row): void {
            $key = $row->service === 'drive'
                ? mb_strtolower((string) ($row->google_account_email ?: $row->uuid))
                : '';

            DB::table(self::TABLE)->where('id', $row->id)->update(['account_key' => $key]);
        });

        // Created before the drop, so the foreign key on user_id always has
        // an index to lean on.
        if (! $this->hasIndex(self::NEW_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(['user_id', 'service', 'account_key']);
            });
        }

        if ($this->hasIndex(self::OLD_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(['user_id', 'service']);
            });
        }

        $this->addProjectOwnership();
    }

    /**
     * Which account holds a project's backup.
     *
     * Required the moment a second account exists: drive_file_id alone names
     * a file without saying whose Drive it is in, and renaming or deleting it
     * means knowing which token to use. Existing rows are attributed to the
     * one Drive connection their owner has, which is correct because until
     * this migration there could not have been another.
     */
    private function addProjectOwnership(): void
    {
        if (! Schema::hasColumn('content_projects', 'drive_connection_id')) {
            Schema::table('content_projects', function (Blueprint $table) {
                // nullOnDelete, not cascade: disconnecting an account must
                // never delete the project that was backed up to it. The
                // backup becomes unattributed, which is a thing to report.
                $table->foreignId('drive_connection_id')
                    ->nullable()
                    ->after('drive_file_id')
                    ->constrained('google_connections')
                    ->nullOnDelete();
            });
        }

        // Guarded by the null check rather than by the migration having run,
        // so a re-run over partially applied DDL finishes the backfill.
        DB::table('content_projects')
            ->whereNull('drive_connection_id')
            ->whereNotNull('drive_file_id')
            ->orderBy('id')
            ->chunkById(500, function ($projects): void {
                foreach ($projects as $project) {
                    $connectionId = DB::table(self::TABLE)
                        ->where('user_id', $project->user_id)
                        ->where('service', 'drive')
                        ->orderBy('id')
                        ->value('id');

                    if ($connectionId !== null) {
                        DB::table('content_projects')
                            ->where('id', $project->id)
                            ->update(['drive_connection_id' => $connectionId]);
                    }
                }
            });
    }

    /**
     * Reverse the columns, without pretending the old key can come back.
     *
     * (user_id, service) cannot be made unique again while a user holds two
     * Drive accounts, and dropping one of those rows to make room would
     * destroy a grant and orphan its backups. So this restores the columns and
     * leaves the key relaxed — a user who added a second account is past the
     * point where one-per-service was true.
     *
     * Order matters twice over:
     *
     *  - The composite unique names account_key, so it has to go before the
     *    column does. Dropping the column first leaves an index referring to
     *    nothing, which SQLite rejects outright.
     *  - user_id carries a foreign key and InnoDB refuses to drop the last
     *    index supporting one, so a plain (user_id, service) index is created
     *    to take over that duty *before* the unique is dropped.
     */
    public function down(): void
    {
        if (Schema::hasColumn('content_projects', 'drive_connection_id')) {
            Schema::table('content_projects', function (Blueprint $table) {
                $table->dropConstrainedForeignId('drive_connection_id');
            });
        }

        if (! $this->hasIndex(self::FALLBACK_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['user_id', 'service'], self::FALLBACK_INDEX);
            });
        }

        if ($this->hasIndex(self::NEW_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(self::NEW_UNIQUE);
            });
        }

        if ($this->hasIndex('google_connections_uuid_unique')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(['uuid']);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            foreach ([
                'uuid', 'account_key', 'account_label', 'account_name', 'priority',
                'storage_limit', 'storage_usage', 'storage_checked_at',
            ] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if ($index['name'] === $name) {
                return true;
            }
        }

        return false;
    }
};
