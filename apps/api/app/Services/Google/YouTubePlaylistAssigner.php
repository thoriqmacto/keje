<?php

namespace App\Services\Google;

use App\Models\ContentProject;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides which playlist an uploaded video joins, and records what happened.
 *
 * Precedence, most specific first:
 *
 *     project.youtube_metadata.playlist_id   an override chosen for this video
 *         ↓ when empty
 *     project.topic.youtube_playlist_id      the topic's standing destination
 *         ↓ when empty
 *     no playlist                            nothing is attempted
 *
 * A project-level override never writes back to the topic: choosing a
 * different destination once is not a decision about the series.
 *
 * Assignment stays independent of the upload. A playlist failure leaves the
 * video uploaded and the project holding an error to retry, because the one
 * thing that must never happen is a retry producing a second video.
 */
class YouTubePlaylistAssigner
{
    public function __construct(
        private readonly YouTubeService $youtube,
        private readonly GoogleErrorTranslator $errors,
    ) {}

    /** The playlist this project should publish into, or null. */
    public function resolve(ContentProject $project): ?string
    {
        $override = $project->youtube_metadata['playlist_id'] ?? null;

        if (filled($override)) {
            return (string) $override;
        }

        $topicPlaylist = $project->topic?->youtube_playlist_id;

        return filled($topicPlaylist) ? (string) $topicPlaylist : null;
    }

    /**
     * Add the project's uploaded video to its resolved playlist.
     *
     * Safe to call repeatedly: it needs an existing video id and never
     * uploads. Returns whether the video is now a member.
     */
    public function assign(ContentProject $project): bool
    {
        $playlistId = $this->resolve($project);
        $videoId = $project->youtube_video_id;

        if (blank($playlistId) || blank($videoId)) {
            return false;
        }

        // Already a member of this exact playlist — nothing to do, and no
        // reason to spend quota confirming it again.
        if ($project->youtube_playlist_item_id !== null && $project->youtube_playlist_id === $playlistId) {
            return true;
        }

        try {
            $itemId = $this->youtube->addToPlaylist($project->user, $playlistId, $videoId);

            $project->forceFill([
                'youtube_playlist_id' => $playlistId,
                'youtube_playlist_item_id' => $itemId,
                'youtube_playlist_added_at' => now(),
                'youtube_playlist_error' => null,
            ])->save();

            return true;
        } catch (Throwable $e) {
            // The video is already there: the desired state, reached earlier.
            if ($this->errors->isAlreadyInPlaylist($e)) {
                $project->forceFill([
                    'youtube_playlist_id' => $playlistId,
                    'youtube_playlist_added_at' => now(),
                    'youtube_playlist_error' => null,
                ])->save();

                return true;
            }

            Log::warning('YouTube playlist assignment failed', [
                'project_id' => $project->id,
                'playlist_id' => $playlistId,
                'exception' => $e,
            ]);

            $project->forceFill([
                'youtube_playlist_id' => $playlistId,
                'youtube_playlist_error' => $this->errors->translate(
                    $e,
                    'Could not add the video to the playlist.',
                ),
            ])->save();

            return false;
        }
    }
}
