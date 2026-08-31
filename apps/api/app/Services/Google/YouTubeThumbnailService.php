<?php

namespace App\Services\Google;

use App\Enums\GoogleService;
use App\Models\ContentProject;
use Google\Http\MediaFileUpload;
use Google\Service\YouTube;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Set a chosen frame as the video's custom thumbnail.
 *
 * thumbnails.set only, never videos.insert. A thumbnail failure must never
 * cost the video: it is already published, and a retry that re-uploaded would
 * put a second copy on the channel. The two operations are kept in separate
 * methods and separate retries for exactly that reason.
 *
 * No new OAuth scope: thumbnails.set is covered by youtube.upload, which every
 * connection already grants. Nobody has to reconnect for this.
 *
 * Not every channel may set a custom thumbnail — the feature needs a verified
 * account — so that refusal is translated into something a person can act on
 * rather than surfaced as a raw Google error.
 */
class YouTubeThumbnailService
{
    public function __construct(
        private readonly GoogleClientFactory $clients,
        private readonly GoogleErrorTranslator $errors,
    ) {}

    /**
     * Upload the stored thumbnail for a project's video.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function set(ContentProject $project): array
    {
        if (blank($project->youtube_video_id)) {
            return ['ok' => false, 'error' => 'This project has no YouTube video yet.'];
        }

        if (blank($project->thumbnail_path)) {
            return ['ok' => false, 'error' => 'No thumbnail has been chosen.'];
        }

        $path = Storage::disk('local')->path($project->thumbnail_path);

        if (! is_file($path)) {
            return ['ok' => false, 'error' => 'The chosen thumbnail is no longer on this server.'];
        }

        $client = $this->clients->forUser($project->user, GoogleService::YouTube);

        try {
            // Deferred so the whole file goes in one resumable request; a
            // thumbnail is small enough that chunking buys nothing.
            $client->setDefer(true);
            $api = new YouTube($client);

            $request = $api->thumbnails->set($project->youtube_video_id);

            $upload = new MediaFileUpload(
                $client,
                $request,
                'image/jpeg',
                (string) file_get_contents($path),
                false,
            );
            $upload->setFileSize(filesize($path));

            $status = $upload->nextChunk();

            return $status === false
                ? ['ok' => false, 'error' => 'YouTube did not confirm the thumbnail.']
                : ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $this->translate($e)];
        } finally {
            $client->setDefer(false);
        }
    }

    /**
     * Channels that are not eligible get a specific refusal, and it is not a
     * bug to report — it is an account state the person has to resolve with
     * Google.
     */
    private function translate(Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'forbidden') || str_contains($message, 'Forbidden')) {
            return 'This channel is not allowed to set custom thumbnails. '
                .'Verify the channel with Google, then retry.';
        }

        return $this->errors->translate($e);
    }
}
