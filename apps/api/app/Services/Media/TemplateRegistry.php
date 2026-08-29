<?php

namespace App\Services\Media;

use App\Exceptions\Media\TemplateNotFoundException;

/**
 * Loads video template definitions from resources/media/templates/<key>/.
 *
 * Adding a template is adding a directory — no renderer or model changes.
 * Definitions are cached per request; they are static files, not user data.
 */
class TemplateRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $loaded = [];

    public function __construct(
        private readonly string $basePath,
        private readonly string $defaultKey,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws TemplateNotFoundException
     */
    public function get(?string $key = null): array
    {
        $key = $key ?: $this->defaultKey;

        if (isset($this->loaded[$key])) {
            return $this->loaded[$key];
        }

        // Templates are addressed by key from the database, so the key is
        // constrained to a safe shape before it ever touches the filesystem.
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $key)) {
            throw new TemplateNotFoundException("Invalid template key: {$key}");
        }

        $file = $this->basePath.'/'.$key.'/template.php';

        if (! is_file($file)) {
            throw new TemplateNotFoundException("Unknown video template: {$key}");
        }

        return $this->loaded[$key] = require $file;
    }

    public function has(string $key): bool
    {
        try {
            $this->get($key);

            return true;
        } catch (TemplateNotFoundException) {
            return false;
        }
    }

    /** Absolute path to a static asset shipped with a template. */
    public function assetPath(string $key, string $asset): string
    {
        // Assets are named by the template definition, never by a request,
        // but basename() keeps a malformed definition from escaping the dir.
        return $this->basePath.'/'.$key.'/'.basename($asset);
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = [];

        foreach (glob($this->basePath.'/*/template.php') ?: [] as $file) {
            $keys[] = basename(dirname($file));
        }

        sort($keys);

        return $keys;
    }

    public function defaultKey(): string
    {
        return $this->defaultKey;
    }
}
