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
 */
return new class extends Migration
{
    private const YOUTUBE_SCOPE = 'youtube';

    private const DRIVE_SCOPE = 'drive';

    public function up(): void
    {
        Schema::table('google_connections', function (Blueprint $table) {
            $table->string('service', 16)->nullable()->after('user_id');
        });

        $this->attributeLegacyConnections();

        Schema::table('google_connections', function (Blueprint $table) {
            // The old shape allowed exactly one connection per user.
            $table->dropUnique(['user_id']);
        });

        Schema::table('google_connections', function (Blueprint $table) {
            $table->string('service', 16)->nullable(false)->change();
            $table->unique(['user_id', 'service']);
        });
    }

    public function down(): void
    {
        // Collapsing two connections back into one would have to discard a
        // service's tokens, so drop them all and let users reconnect.
        DB::table('google_connections')->delete();

        Schema::table('google_connections', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'service']);
            $table->dropColumn('service');
        });

        Schema::table('google_connections', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    /**
     * Give each pre-split row a service, or remove it.
     *
     * Runs on raw rows rather than the model, because the model now requires
     * the very column this migration is adding.
     */
    private function attributeLegacyConnections(): void
    {
        $rows = DB::table('google_connections')->select('id', 'scopes')->get();

        foreach ($rows as $row) {
            $service = $this->serviceFor($row->scopes);

            if ($service === null) {
                DB::table('google_connections')->where('id', $row->id)->delete();

                continue;
            }

            DB::table('google_connections')->where('id', $row->id)->update(['service' => $service]);
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
        $hasYouTube = str_contains($joined, self::YOUTUBE_SCOPE);
        $hasDrive = str_contains($joined, self::DRIVE_SCOPE);

        return match (true) {
            $hasYouTube && ! $hasDrive => 'youtube',
            $hasDrive && ! $hasYouTube => 'drive',
            // Both (the combined grant this migration exists to undo) or
            // neither: not attributable.
            default => null,
        };
    }
};
