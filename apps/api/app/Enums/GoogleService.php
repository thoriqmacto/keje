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
                'https://www.googleapis.com/auth/youtube.upload',
                'https://www.googleapis.com/auth/youtube.readonly',
            ],
            self::Drive => [
                'https://www.googleapis.com/auth/drive.file',
            ],
        };
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
