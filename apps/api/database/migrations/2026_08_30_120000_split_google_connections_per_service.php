<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One Google connection per service, instead of one per user.
 *
 * Google refuses a consent request that mixes the YouTube scopes with
 * drive.file, so Keje now authorizes each product through its own OAuth
 * client. That means a user holds up to two connections, keyed by service.
 *
 * Forward-only: the original create migration is left untouched.
 *
 * Legacy rows are attributed when their stored scopes name exactly one
 * service, and deleted otherwise. A combined connection is deleted on
 * purpose: its grant covers both products, so keeping it under either
 * service would claim an authorization the new, separate OAuth clients do
 * not hold. Those users reconnect each service once.
 *
 * Index order matters on MySQL/MariaDB. `user_id` carries both a unique index
 * and a foreign key, and InnoDB refuses to drop the last index that supports
 * an FK ("needed in a foreign key constraint"). So the composite unique is
 * created *first*: with `user_id` as its leftmost column it can carry the
 * foreign key, which then frees the old single-column index to be dropped.
 * The foreign key itself is never touched.
 *
 * Every step is guarded, because a run that failed partway on MySQL leaves
 * its committed DDL behind — MySQL cannot roll back schema changes — and the
 * migration must be safe to re-run over that partial state.
 */
return new class extends Migration
{
    private const TABLE = 'google_connections';

    private const OLD_UNIQUE = 'google_connections_user_id_unique';

    private const NEW_UNIQUE = 'google_connections_user_id_service_unique';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'service')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('service', 16)->nullable()->after('user_id');
            });
        }

        $this->attributeLegacyConnections();

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->string('service', 16)->nullable(false)->change();
        });

        // Before the drop below, so the foreign key on user_id always has an
        // index to lean on.
        if (! $this->hasIndex(self::NEW_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(['user_id', 'service']);
            });
        }

        if ($this->hasIndex(self::OLD_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(['user_id']);
            });
        }
    }

    public function down(): void
    {
        // Collapsing two connections back into one would have to discard a
        // service's tokens, so drop them all and let users reconnect. This
        // also clears the duplicate user_ids that the unique index below
        // would otherwise reject.
        DB::table(self::TABLE)->delete();

        // Same ordering constraint in reverse: restore the single-column
        // index before removing the composite one the foreign key is using.
        if (! $this->hasIndex(self::OLD_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique('user_id');
            });
        }

        if ($this->hasIndex(self::NEW_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(['user_id', 'service']);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'service')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn('service');
            });
        }
    }

    /**
     * Give each pre-split row a service, or remove it.
     *
     * Runs on raw rows rather than the model, because the model now requires
     * the very column this migration is adding. Only untouched rows are
     * considered, so re-running after a partial failure leaves already
     * attributed connections alone.
     */
    private function attributeLegacyConnections(): void
    {
        $rows = DB::table(self::TABLE)->whereNull('service')->select('id', 'scopes')->get();

        foreach ($rows as $row) {
            $service = $this->serviceFor($row->scopes);

            if ($service === null) {
                DB::table(self::TABLE)->where('id', $row->id)->delete();

                continue;
            }

            DB::table(self::TABLE)->where('id', $row->id)->update(['service' => $service]);
        }
    }

    /** The single service these scopes describe, or null when that is not clear. */
    private function serviceFor(?string $encryptedScopes): ?string
    {
        if (blank($encryptedScopes)) {
            return null;
        }

        try {
            $scopes = json_decode(Crypt::decryptString($encryptedScopes), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // Unreadable (rotated APP_KEY, hand-edited row): require reconnect
            // rather than guess at what was granted.
            return null;
        }

        if (! is_array($scopes)) {
            return null;
        }

        $joined = implode(' ', array_map(strval(...), $scopes));
        $hasYouTube = str_contains($joined, 'youtube');
        $hasDrive = str_contains($joined, 'drive');

        return match (true) {
            $hasYouTube && ! $hasDrive => 'youtube',
            $hasDrive && ! $hasYouTube => 'drive',
            // Both (the combined grant this migration exists to undo) or
            // neither: not attributable.
            default => null,
        };
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
