<?php

namespace App\Enums;

/**
 * A Google product Keje authorizes independently.
 *
 * Google refuses any authorization request that mixes the YouTube scopes with
 * drive.file — "scopes that cannot be requested together" — so each product
 * gets its own OAuth client, its own consent flow, and its own stored
 * connection. Nothing here may ever be combined into one request.
 */
enum GoogleService: string
{
    public const SCOPE_YOUTUBE_UPLOAD = 'https://www.googleapis.com/auth/youtube.upload';

    public const SCOPE_YOUTUBE_READONLY = 'https://www.googleapis.com/auth/youtube.readonly';

    public const SCOPE_YOUTUBE_FORCE_SSL = 'https://www.googleapis.com/auth/youtube.force-ssl';

    public const SCOPE_DRIVE_FILE = 'https://www.googleapis.com/auth/drive.file';

    case YouTube = 'youtube';
    case Drive = 'drive';

    /**
     * The only scopes this service ever asks for. Least privilege:
     * youtube.upload uploads, youtube.readonly reads back the channel so it
     * can be verified, drive.file sees only files Keje itself created.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::YouTube => [
                self::SCOPE_YOUTUBE_UPLOAD,
                self::SCOPE_YOUTUBE_READONLY,
                // playlistItems.insert is a write against the channel, which
                // neither .upload nor .readonly covers. Without it a video
                // uploads fine and then cannot be added to a playlist.
                self::SCOPE_YOUTUBE_FORCE_SSL,
            ],
            self::Drive => [
                self::SCOPE_DRIVE_FILE,
            ],
        };
    }

    /**
     * What a connection holding these granted scopes can actually do.
     *
     * Derived from the scopes Google returned at consent, never from the
     * presence of configuration: a client id in the environment says nothing
     * about what the stored grant permits. A connection made before a scope
     * was added keeps working for everything it does cover.
     *
     * @param  list<string>  $granted
     * @return array<string, bool>
     */
    public function capabilities(array $granted): array
    {
        $has = static fn (string ...$any): bool => (bool) array_intersect($any, $granted);

        return match ($this) {
            self::YouTube => [
                'read_channel' => $has(self::SCOPE_YOUTUBE_READONLY, self::SCOPE_YOUTUBE_FORCE_SSL),
                'upload_video' => $has(self::SCOPE_YOUTUBE_UPLOAD),
                'manage_playlists' => $has(self::SCOPE_YOUTUBE_FORCE_SSL),
            ],
            self::Drive => [
                // drive.file covers both: Keje only ever sees what it created.
                'about' => $has(self::SCOPE_DRIVE_FILE),
                'backup' => $has(self::SCOPE_DRIVE_FILE),
            ],
        };
    }

    /**
     * Capabilities this service would have on a fresh connection.
     *
     * The difference against a stored connection's capabilities is exactly
     * what reconnecting would gain, which is what the UI offers.
     *
     * @return array<string, bool>
     */
    public function fullCapabilities(): array
    {
        return $this->capabilities($this->scopes());
    }

    /** Human-facing name, used in error messages and the UI. */
    public function label(): string
    {
        return match ($this) {
            self::YouTube => 'YouTube',
            self::Drive => 'Google Drive',
        };
    }

    /** config() key holding this service's OAuth client credentials. */
    public function configKey(): string
    {
        return "services.google.clients.{$this->value}";
    }

    /** Env prefix, so configuration errors can name the missing variables. */
    public function envPrefix(): string
    {
        return match ($this) {
            self::YouTube => 'GOOGLE_YOUTUBE',
            self::Drive => 'GOOGLE_DRIVE',
        };
    }
}
