<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContentProject;
use App\Services\Media\MediaInventoryService;
use App\Services\Media\MediaRetention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What Keje is keeping on local disk, and the safe ways to reduce it.
 *
 * The safety boundary is the whole design. Nothing here accepts a path: the
 * browser sends an intent — show me the inventory, preview a prune, prune the
 * eligible ones — and the server decides which files that means. An endpoint
 * that took a path would be a remote file manager wearing a storage page, and
 * no amount of validation makes that a good idea.
 *
 * Eligibility is never decided here either. MediaRetention owns those rules
 * because it is what actually deletes the files; a second opinion in this
 * controller would eventually disagree with it, and the disagreement would
 * surface as a page promising to free space it then refuses to.
 */
class StorageController extends Controller
{
    public function __construct(
        private readonly MediaInventoryService $inventory,
        private readonly MediaRetention $retention,
    ) {}

    /** The full inventory: totals, per-project rows, and orphaned directories. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->inventory->forUser($request->user())]);
    }

    /**
     * What a bulk prune would free, and what it would decline to touch.
     *
     * Its own endpoint rather than a flag on the prune, so the preview cannot
     * accidentally be the thing that deletes.
     */
    public function prunePreview(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->inventory->prunePreview($request->user())]);
    }

    /**
     * Prune everything currently eligible.
     *
     * Re-evaluated per project as it runs rather than trusted from the
     * preview: a correction window can close and a replacement can start
     * between the two requests, and the second is the one that deletes.
     */
    public function prune(Request $request): JsonResponse
    {
        $freed = 0;
        $pruned = 0;
        $skipped = 0;

        $projects = ContentProject::query()
            ->where('user_id', $request->user()->id)
            ->where(function ($query): void {
                $query->whereNotNull('output_path')
                    ->orWhereNotNull('source_audio_path')
                    ->orWhereNotNull('background_image_path');
            })
            ->get();

        foreach ($projects as $project) {
            if (! $this->retention->explain($project)['eligible']) {
                $skipped++;

                continue;
            }

            $result = $this->retention->prune($project);

            if ($result['bytes'] > 0) {
                $freed += $result['bytes'];
                $pruned++;
            }
        }

        return response()->json([
            'message' => $pruned === 0
                ? 'Nothing was eligible to prune.'
                : "Freed local media for {$pruned} project(s).",
            'data' => [
                'pruned' => $pruned,
                'skipped' => $skipped,
                'bytes_freed' => $freed,
            ],
        ]);
    }
}
