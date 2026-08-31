<?php

namespace App\Support;

/**
 * What the current OS user can actually do with a path, and why not.
 *
 * "The file is missing" and "a directory above it is not traversable" are
 * indistinguishable from PHP: without execute permission on a parent, is_file()
 * and file_exists() both return false for a file that is sitting right there.
 * Reporting that as missing sends someone off to re-upload media that was never
 * lost, so this walks the parents and names the directory that actually blocks.
 *
 * Everything here degrades rather than throws: posix_* is optional, stat can
 * fail, and Windows has no owner/group to report. A diagnostic that crashes on
 * the host it was written for is worse than one that says "unknown".
 */
class PathAccess
{
    public const OK = 'ok';

    /** The path is genuinely not there — every parent was traversable. */
    public const MISSING = 'missing';

    /** A directory above it cannot be entered, so its existence is unknowable. */
    public const BLOCKED = 'blocked';

    /** It exists and is reachable, but this user cannot read it. */
    public const UNREADABLE = 'unreadable';

    private function __construct(
        public readonly string $path,
        public readonly string $status,
        public readonly ?string $owner,
        public readonly ?string $group,
        public readonly ?string $mode,
        /** First parent directory this user cannot traverse, when status is BLOCKED. */
        public readonly ?string $blockedAt,
    ) {}

    /**
     * Inspect a path, walking its parents when it appears to be absent.
     *
     * @param  string|null  $stopAt  outermost directory worth reporting on; parents
     *                               above it are still checked but never blamed,
     *                               so the report stays inside the app
     */
    public static function inspect(string $path, ?string $stopAt = null): self
    {
        clearstatcache();

        if (is_file($path) || is_dir($path)) {
            return new self(
                $path,
                is_readable($path) ? self::OK : self::UNREADABLE,
                self::owner($path),
                self::group($path),
                self::mode($path),
                null,
            );
        }

        // Not visible. Either it is really gone, or something above it is shut.
        $blocker = self::firstUntraversableParent($path, $stopAt);

        if ($blocker !== null) {
            return new self(
                $path,
                self::BLOCKED,
                self::owner($blocker),
                self::group($blocker),
                self::mode($blocker),
                $blocker,
            );
        }

        return new self($path, self::MISSING, null, null, null, null);
    }

    public function ok(): bool
    {
        return $this->status === self::OK;
    }

    /** One sentence naming the problem, aimed at whoever has to fix it. */
    public function explain(): string
    {
        return match ($this->status) {
            self::OK => 'readable',
            self::MISSING => 'no file at this path',
            self::UNREADABLE => sprintf(
                'exists but is not readable by %s (owner %s, group %s, mode %s)',
                self::currentUser(),
                $this->owner ?? '?',
                $this->group ?? '?',
                $this->mode ?? '?',
            ),
            self::BLOCKED => sprintf(
                'cannot be reached: %s is not traversable by %s (owner %s, group %s, mode %s)',
                $this->blockedAt,
                self::currentUser(),
                $this->owner ?? '?',
                $this->group ?? '?',
                $this->mode ?? '?',
            ),
            default => $this->status,
        };
    }

    /**
     * The outermost existing directory on the way to $path that this user
     * cannot enter.
     *
     * Outermost, not innermost: it is the first closed door that stops the
     * walk, and the one whose mode has to change.
     */
    public static function firstUntraversableParent(string $path, ?string $stopAt = null): ?string
    {
        $parents = [];

        for ($current = dirname($path); $current !== dirname($current); $current = dirname($current)) {
            $parents[] = $current;

            if ($stopAt !== null && rtrim($current, '/') === rtrim($stopAt, '/')) {
                break;
            }
        }

        foreach (array_reverse($parents) as $parent) {
            if (! is_dir($parent)) {
                // Reached a directory that does not exist. Nothing below it can
                // exist either, so this is absence, not a permission problem.
                return null;
            }

            if (! is_executable($parent)) {
                return $parent;
            }
        }

        return null;
    }

    /** Octal mode, e.g. "2770" — with the setgid bit visible. */
    public static function mode(string $path): ?string
    {
        $perms = self::perms($path);

        return $perms === null ? null : substr(sprintf('%o', $perms), -4);
    }

    public static function owner(string $path): ?string
    {
        clearstatcache(true, $path);

        return self::name(@fileowner($path), 'posix_getpwuid');
    }

    public static function group(string $path): ?string
    {
        clearstatcache(true, $path);

        return self::name(@filegroup($path), 'posix_getgrgid');
    }

    /** The setgid bit — what makes new files inherit the directory's group. */
    public static function hasSetgid(string $path): bool
    {
        $perms = self::perms($path);

        return $perms !== null && ($perms & 02000) !== 0;
    }

    /**
     * fileperms() reads PHP's stat cache, which survives a chmod made by
     * something else — another process, or a fix applied between two calls in
     * the same command. A diagnostic reporting a mode that is no longer true
     * is worse than one that is slow, so every read is uncached.
     */
    private static function perms(string $path): ?int
    {
        clearstatcache(true, $path);

        $perms = @fileperms($path);

        return $perms === false ? null : $perms;
    }

    public static function currentUser(): string
    {
        if (! function_exists('posix_geteuid')) {
            return get_current_user() ?: 'this user';
        }

        return self::name(posix_geteuid(), 'posix_getpwuid') ?? 'this user';
    }

    /**
     * Running as root makes every permission check pass, so a clean report
     * from root says nothing about the deploy or web user.
     */
    public static function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /** Group names the current user belongs to, or null when unknowable. */
    public static function currentGroups(): ?array
    {
        if (! function_exists('posix_getgroups') || ! function_exists('posix_getgrgid')) {
            return null;
        }

        $names = [];

        foreach (posix_getgroups() as $gid) {
            $names[] = self::name($gid, 'posix_getgrgid') ?? (string) $gid;
        }

        sort($names);

        return $names;
    }

    private static function name(int|false|null $id, string $lookup): ?string
    {
        if ($id === false || $id === null) {
            return null;
        }

        if (! function_exists($lookup)) {
            return (string) $id;
        }

        $entry = @$lookup($id);

        return is_array($entry) ? ($entry['name'] ?? (string) $id) : (string) $id;
    }
}
