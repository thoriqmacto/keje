<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexContentProjectRequest;
use App\Services\Studio\FinishOutdatedService;
use Illuminate\Http\JsonResponse;

/**
 * Re-render every outdated project in the current Studio view.
 *
 * Two endpoints, because a bulk action that cannot be inspected before it runs
 * is one people learn not to press. The plan says what would happen; the
 * execution does it and re-checks everything on the way.
 *
 * The filters arrive as the same query string the Studio list uses and are
 * validated by the same request class, so "the current view" means precisely
 * what the table was showing — not the page of it that was visible.
 *
 * Neither endpoint can reach YouTube or Drive. That is the point: some of
 * these projects have published videos, and replacing one is a confirmed,
 * one-at-a-time action for good reasons.
 */
class FinishOutdatedController extends Controller
{
    public function __construct(
        private readonly FinishOutdatedService $finish,
    ) {}

    public function plan(IndexContentProjectRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->finish->plan($request->user(), $request->validated()),
        ]);
    }

    public function store(IndexContentProjectRequest $request): JsonResponse
    {
        $result = $this->finish->execute($request->user(), $request->validated());

        return response()->json([
            'message' => $result['queued'] === 0
                ? 'No projects needed a fresh render.'
                : "Queued {$result['queued']} project(s) for rendering.",
            'data' => $result,
        ], 202);
    }
}
