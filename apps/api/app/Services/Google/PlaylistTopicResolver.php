<?php

namespace App\Services\Google;

use App\Models\ContentTopic;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A YouTube playlist, as the topic a project belongs to.
 *
 * These were always the same grouping described twice: a local ContentTopic
 * called "Riyadhush Shalihin" and a playlist called "Riyadhush Shalihin",
 * maintained separately and mapped by hand. The playlist is now canonical and
 * the ContentTopic is its local shadow.
 *
 * The shadow is not redundant. It carries what YouTube has no concept of — the
 * name drawn on the Kajian Tematik frame, the TEMA sequence, and the
 * relationship to every historical project — so dropping the table would break
 * rendering and orphan finished work.
 *
 * Identity is the playlist id, never the name. Two topics that happen to share
 * a title are not the same topic, and merging them on a string match would
 * silently reassign someone's projects.
 */
class PlaylistTopicResolver
{
    /**
     * Find or create the local topic for a playlist.
     *
     * Re-running with a changed title renames the shadow, which is right: the
     * playlist is the source of truth and someone renamed it there.
     */
    public function resolve(User $user, string $playlistId, ?string $title = null): ContentTopic
    {
        $topic = ContentTopic::where('user_id', $user->id)
            ->where('youtube_playlist_id', $playlistId)
            ->first();

        if ($topic !== null) {
            if (filled($title) && $topic->name !== $title) {
                $topic->forceFill(['name' => $title])->save();
            }

            return $topic;
        }

        // A legacy topic with the same name and no playlist yet is almost
        // certainly the one this playlist was created for, so adopt it rather
        // than leaving a duplicate beside it. Only ever an unmapped topic:
        // one already pointing elsewhere belongs to a different playlist.
        if (filled($title)) {
            $legacy = ContentTopic::where('user_id', $user->id)
                ->whereNull('youtube_playlist_id')
                ->where('name', $title)
                ->first();

            if ($legacy !== null) {
                $legacy->forceFill(['youtube_playlist_id' => $playlistId])->save();

                return $legacy;
            }
        }

        $topic = new ContentTopic([
            'name' => $title ?: $playlistId,
            'slug' => $this->uniqueSlug($user->id, $title ?: $playlistId),
            'youtube_playlist_id' => $playlistId,
        ]);
        $topic->user()->associate($user);
        $topic->save();

        return $topic;
    }

    private function uniqueSlug(int $userId, string $name): string
    {
        $base = Str::slug($name) ?: 'topic';
        $slug = $base;
        $i = 2;

        while (ContentTopic::where('user_id', $userId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
