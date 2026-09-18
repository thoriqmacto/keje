<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recording already on the server, offered for reuse.
 *
 * Facts about the file, never a path to it — the same rule the project
 * resources follow. What identifies a choice here is the *project* that holds
 * the recording, because that is what the reuse endpoint accepts and what
 * somebody would recognise: "the one from Kajian #11", not a filename under a
 * UUID they have never seen.
 *
 * @mixin \App\Models\ContentProject
 */
class AudioSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // The project to copy from, which is what POST .../audio/reuse
            // takes. There is no file identifier in this payload at all.
            'id' => $this->uuid,
            'working_title' => $this->working_title,
            'topic' => $this->topic?->name,

            'original_name' => $this->source_audio_original_name,
            'mime' => $this->source_audio_mime,
            'size' => $this->source_audio_size,
            'duration' => $this->source_audio_duration,
            'codec' => $this->source_audio_codec,

            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
