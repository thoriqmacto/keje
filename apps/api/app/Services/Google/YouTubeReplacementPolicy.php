<?php

namespace App\Services\Google;

use App\Enums\GoogleService;
use App\Models\ContentProject;
use App\Services\Media\RenderInputFingerprint;
use Illuminate\Support\Facades\Storage;

/**
 * Whether this project may be corrected, and if not, exactly why.
 *
 * Split out from the workflow because the same answer is needed in three
 * places that must not disagree: the endpoint that refuses to start, the UI
 * that greys out the button, and the sentence explaining what to do instead.
 * Three independent versions of "is this replaceable" would eventually offer a
 * button that the API then rejects.
 *
 * Every reason is returned as a code plus a sentence rather than a bare
 * boolean, because "you cannot replace this" is useless on its own — the
 * useful part is that the source audio was cleaned up, or that the render is
 * older than the edits.
 */
class YouTubeReplacementPolicy
{
    public function __construct(
        private readonly RenderInputFingerprint $fingerprint,
        private readonly GoogleClientFactory $clients,
    ) {}

    /**
     * @return array{allowed:bool, reason:?string, message:?string, needs_render:bool, needs_reconnect:bool, needs_media:bool}
     */
    public function evaluate(ContentProject $project): array
    {
        $deny = static fn (string $reason, string $message, array $flags = []): array => array_merge([
            'allowed' => false,
            'reason' => $reason,
            'message' => $message,
            'needs_render' => false,
            'needs_reconnect' => false,
            'needs_media' => false,
        ], $flags);

        if (blank($project->youtube_video_id)) {
            return $deny(
                'not_published',
                'This project has no video on YouTube to replace.',
            );
        }

        if (! $this->clients->isConfigured(GoogleService::YouTube)) {
            return $deny('not_configured', 'YouTube is not configured on the server.');
        }

        $connection = $project->user->googleConnectionFor(GoogleService::YouTube);

        if ($connection === null) {
            return $deny(
                'not_connected',
                'Connect YouTube from Settings → Integrations first.',
                ['needs_reconnect' => true],
            );
        }

        // Deleting the old video is the step that needs a permission beyond
        // uploading. Discovering that after the replacement is already
        // uploaded would strand a private copy on the channel, so it is
        // checked before anything is sent.
        if (! ($connection->capabilities()['manage_videos'] ?? false)) {
            return $deny(
                'missing_scope',
                'Reconnect YouTube to allow Keje to remove the old video during a replacement.',
                ['needs_reconnect' => true],
            );
        }

        if ($connection->matchesExpectedChannel() === false) {
            return $deny(
                'wrong_channel',
                'The connected YouTube channel is not the expected one. Reconnect YouTube with the correct account.',
                ['needs_reconnect' => true],
            );
        }

        // The corrected file has to exist. This is where the retention policy
        // and the correction workflow meet: a project whose media was cleaned
        // up after backup cannot be re-rendered, and must not be offered a
        // replacement that would then have nothing to upload.
        if (blank($project->output_path)) {
            return $deny(
                'no_render',
                $project->hasRequiredMedia()
                    ? 'Render the corrected video before replacing the one on YouTube.'
                    : 'The source media was cleaned from this server after the Drive backup. Upload the audio and artwork again before re-rendering.',
                ['needs_render' => true, 'needs_media' => ! $project->hasRequiredMedia()],
            );
        }

        if (! Storage::disk('local')->exists($project->output_path)) {
            return $deny(
                'render_missing',
                'The rendered video is no longer on this server. Render it again before replacing the video on YouTube.',
                ['needs_render' => true],
            );
        }

        // The render is older than the project's own inputs, so uploading it
        // would replace the video with something that is *also* wrong.
        if ($this->fingerprint->isStale($project)) {
            return $deny(
                'render_stale',
                'You changed the project but have not rendered the corrected version yet.',
                ['needs_render' => true],
            );
        }

        // Nothing to correct: the video on YouTube was made from exactly this
        // render. Offering to replace it would delete a video and upload an
        // identical one, losing the URL and the comments for nothing.
        if (! $this->renderDiffersFromPublished($project)) {
            return $deny(
                'already_current',
                'The video on YouTube was made from the current render. Edit its metadata instead.',
            );
        }

        return [
            'allowed' => true,
            'reason' => null,
            'message' => null,
            'needs_render' => false,
            'needs_reconnect' => false,
            'needs_media' => false,
        ];
    }

    public function allows(ContentProject $project): bool
    {
        return $this->evaluate($project)['allowed'];
    }

    /**
     * The current render is not the one behind the published video.
     *
     * Unknown hashes are treated as "different": a video uploaded before Keje
     * recorded render identity cannot be proven current, and refusing to let
     * someone correct it would be worse than offering a replacement they may
     * not strictly need.
     */
    private function renderDiffersFromPublished(ContentProject $project): bool
    {
        if (blank($project->youtube_render_input_hash)) {
            return true;
        }

        return $this->fingerprint->for($project) !== $project->youtube_render_input_hash;
    }
}
