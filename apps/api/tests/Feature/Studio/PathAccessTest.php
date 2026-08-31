<?php

namespace Tests\Feature\Studio;

use App\Support\PathAccess;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Telling "missing" apart from "unreachable" is the whole point of this class:
 * PHP reports both as false, and reporting the second as the first sends
 * someone off to re-upload media that is sitting on disk.
 *
 * Permission behaviour cannot be asserted as root — root passes every check —
 * and Windows has no mode bits, so those cases skip rather than fail. The
 * logic that does not depend on the OS is always asserted.
 */
class PathAccessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/keje-perm-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/content/abc/source', 0755, true);
    }

    protected function tearDown(): void
    {
        // Re-open anything a test closed, or the cleanup cannot descend.
        @chmod($this->root.'/content/abc', 0755);
        @exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    private function skipUnlessPermissionsApply(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX permissions do not apply on this platform.');
        }

        if (PathAccess::isRoot()) {
            $this->markTestSkipped('root bypasses permission checks.');
        }
    }

    #[Test]
    public function a_readable_file_is_reported_as_ok(): void
    {
        $file = $this->root.'/content/abc/source/audio.mp3';
        file_put_contents($file, 'x');

        $access = PathAccess::inspect($file, $this->root);

        $this->assertSame(PathAccess::OK, $access->status);
        $this->assertTrue($access->ok());
        $this->assertNull($access->blockedAt);
    }

    #[Test]
    public function a_genuinely_absent_file_is_reported_as_missing(): void
    {
        $access = PathAccess::inspect($this->root.'/content/abc/source/audio.mp3', $this->root);

        $this->assertSame(PathAccess::MISSING, $access->status);
        $this->assertNull($access->blockedAt);
        $this->assertStringContainsString('no file at this path', $access->explain());
    }

    #[Test]
    public function a_file_under_a_missing_directory_is_missing_not_blocked(): void
    {
        // Nothing is locked; the tree simply is not there. Blaming
        // permissions here would send someone chasing a chmod for nothing.
        $access = PathAccess::inspect($this->root.'/content/zzz/source/audio.mp3', $this->root);

        $this->assertSame(PathAccess::MISSING, $access->status);
    }

    #[Test]
    public function a_file_behind_a_closed_directory_is_blocked_not_missing(): void
    {
        $this->skipUnlessPermissionsApply();

        $file = $this->root.'/content/abc/source/audio.mp3';
        file_put_contents($file, 'x');

        // Exactly what Flysystem used to create for every project directory.
        chmod($this->root.'/content/abc', 0000);

        $access = PathAccess::inspect($file, $this->root);

        $this->assertSame(PathAccess::BLOCKED, $access->status);
        $this->assertSame($this->root.'/content/abc', $access->blockedAt);

        // The distinction is the point: the message must not say "missing".
        $this->assertStringContainsString('cannot be reached', $access->explain());
        $this->assertStringNotContainsString('no file at this path', $access->explain());
    }

    #[Test]
    public function the_outermost_closed_directory_is_the_one_reported(): void
    {
        $this->skipUnlessPermissionsApply();

        $file = $this->root.'/content/abc/source/audio.mp3';
        file_put_contents($file, 'x');
        chmod($this->root.'/content/abc/source', 0000);
        chmod($this->root.'/content/abc', 0000);

        // The first closed door is what stops the walk, and the one whose
        // mode has to change first.
        $this->assertSame(
            $this->root.'/content/abc',
            PathAccess::inspect($file, $this->root)->blockedAt,
        );
    }

    #[Test]
    public function a_reachable_but_unreadable_file_is_neither_missing_nor_blocked(): void
    {
        $this->skipUnlessPermissionsApply();

        $file = $this->root.'/content/abc/source/audio.mp3';
        file_put_contents($file, 'x');
        chmod($file, 0000);

        $access = PathAccess::inspect($file, $this->root);

        $this->assertSame(PathAccess::UNREADABLE, $access->status);
        $this->assertStringContainsString('not readable', $access->explain());
    }

    #[Test]
    public function mode_owner_and_group_are_formatted_without_crashing(): void
    {
        $file = $this->root.'/content/abc/source/audio.mp3';
        file_put_contents($file, 'x');

        $access = PathAccess::inspect($file, $this->root);

        // Never assert a specific owner: CI, Docker and a VPS all differ.
        $this->assertMatchesRegularExpression('/^\d{4}$/', (string) $access->mode);
        $this->assertNotSame('', (string) $access->owner);
        $this->assertNotSame('', (string) $access->group);
    }

    #[Test]
    public function the_setgid_bit_is_detected(): void
    {
        $this->skipUnlessPermissionsApply();

        $dir = $this->root.'/content/abc';

        chmod($dir, 0770);
        $this->assertFalse(PathAccess::hasSetgid($dir));

        chmod($dir, 02770);
        $this->assertTrue(PathAccess::hasSetgid($dir));
        $this->assertSame('2770', PathAccess::mode($dir));
    }

    #[Test]
    public function identity_helpers_answer_on_any_platform(): void
    {
        $this->assertNotSame('', PathAccess::currentUser());

        $groups = PathAccess::currentGroups();
        $this->assertTrue($groups === null || is_array($groups));
    }
}
