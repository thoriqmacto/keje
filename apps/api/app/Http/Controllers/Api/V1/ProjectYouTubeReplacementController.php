<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OldVideoDisposition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StartYouTubeReplacementRequest;
use App\Http\Resources\Api\V1\ContentProjectResource;
use App\Http\Resources\Api\V1\YouTubeReplacementResource;
use App\Models\ContentProject;
use App\Services\Google\ReplacementConflictException;
use App\Services\Google\YouTubeReplacementPolicy;
use App\Services\Google\YouTubeReplacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The replacement workflow's four verbs: start, read, retry, cancel.
 *
 * Four rather than one per state-machine step, deliberately. The browser
 * expresses an intention — "replace this video", "try that again" — and the
 * backend decides what sequence of YouTube calls that implies. Exposing the
 * individual steps would move the ordering guarantees into the client, where
 * an out-of-date tab could delete a video before its replacement existed.
 *
 * Nothing here takes a video id from the request. The video being replaced is
 * whatever the project currently points at; accepting one from the browser
 * would turn a correction endpoint into an arbitrary-video deletion endpoint.
 */
class ProjectYouTubeReplacementController extends Controller
{
    public function __construct(
        private readonly YouTubeReplacementService $replacements,
        private readonly YouTubeReplacementPolicy $policy,
    ) {}

    /** Begin correcting the published video. */
    public function store(StartYouTubeReplacementRequest $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        $disposition = OldVideoDisposition::from(
            (string) ($request->validated('old_disposition') ?? OldVideoDisposition::Delete->value),
        );

        try {
            $replacement = $this->replacements->start($project, $disposition);
        } catch (ReplacementConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data' => new ContentProjectResource($project->fresh(['topic', 'speaker'])),
            ], 409);
        } catch (RuntimeException $e) {
            // A precondition, not a crash: no fresh render, missing scope,
            // nothing to replace. The message says which.
            throw ValidationException::withMessages(['replacement' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Replacement started. The corrected video is uploading privately; your published video has not changed yet.',
            'data' => new YouTubeReplacementResource($replacement),
        ], 202);
    }

    /** Current workflow state, for the progress display. */
    public function show(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        $replacement = $project->activeYouTubeReplacement()
            ?? $project->youtubeReplacements()->latest('id')->first();

        return response()->json([
            'data' => $replacement === null ? null : new YouTubeReplacementResource($replacement),
            // Sent alongside so a UI that finds no replacement still knows
            // whether to offer one, and why not.
            'eligibility' => $this->policy->evaluate($project),
        ]);
    }

    /**
     * Resume a stalled replacement from wherever it actually stopped.
     *
     * Cannot re-upload: the service resumes from the persisted facts, and a
     * replacement that already holds a video id has its upload stage behind
     * it permanently.
     */
    public function retry(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        $replacement = $project->activeYouTubeReplacement();

        if ($replacement === null) {
            throw ValidationException::withMessages([
                'replacement' => ['There is no replacement to retry for this project.'],
            ]);
        }

        try {
            $replacement = $this->replacements->retry($replacement);
        } catch (ReplacementConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data' => new YouTubeReplacementResource($replacement),
            ], 409);
        }

        return response()->json([
            'message' => 'Continuing the replacement from where it stopped.',
            'data' => new YouTubeReplacementResource($replacement),
        ], 202);
    }

    /** Abandon a replacement, cleaning up the temporary private copy. */
    public function cancel(Request $request, ContentProject $project): JsonResponse
    {
        abort_unless($request->user()->can('update', $project), 404);

        $replacement = $project->activeYouTubeReplacement();

        if ($replacement === null) {
            throw ValidationException::withMessages([
                'replacement' => ['There is no replacement to cancel for this project.'],
            ]);
        }

        try {
            $replacement = $this->replacements->cancel($replacement);
        } catch (ReplacementConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data' => new YouTubeReplacementResource($replacement),
            ], 409);
        }

        return response()->json([
            'message' => $replacement->hasReplacementVideo()
                ? 'Cancelling: the temporary private copy is being deleted.'
                : 'Replacement cancelled.',
            'data' => new YouTubeReplacementResource($replacement),
        ], 202);
    }
}
