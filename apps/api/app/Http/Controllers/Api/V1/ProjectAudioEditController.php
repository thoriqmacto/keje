<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProjectAudioEditsRequest;
use App\Http\Resources\Api\V1\ContentProjectResource;
use App\Models\ContentProject;
use App\Services\Media\AudioEditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Removed sections of the recording.
 *
 * Saving edits never touches the uploaded MP3 — the list is applied at encode
 * time — so this is a cheap, reversible operation that costs a re-render and
 * nothing else. Changing it does make an existing output stale, which the
 * render resource reports; that is handled by the fingerprint rather than by
 * resetting a status here.
 */
class ProjectAudioEditController extends Controller
{
    public function __construct(
        private readonly AudioEditService $edits,
    ) {}

    public function update(UpdateProjectAudioEditsRequest $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        if (blank($project->source_audio_path)) {
            throw ValidationException::withMessages([
                'audio_edits' => ['Upload the lecture recording before choosing what to remove.'],
            ]);
        }

        $cuts = $this->edits->normalize(
            $request->validated('audio_edits'),
            $project->source_audio_duration === null ? null : (float) $project->source_audio_duration,
        );

        $project->forceFill(['audio_edits' => $cuts === [] ? null : $cuts])->save();

        return response()->json([
            'data' => new ContentProjectResource($project->load(['topic', 'speaker'])),
        ]);
    }
}
