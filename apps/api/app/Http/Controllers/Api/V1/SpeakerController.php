<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSpeakerRequest;
use App\Http\Requests\Api\V1\UpdateSpeakerRequest;
use App\Http\Resources\Api\V1\SpeakerResource;
use App\Models\Speaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reusable speakers. Stored in natural case — a template deciding to render
 * "SYAFIQ RIZA BASALAMAH" never rewrites the record.
 */
class SpeakerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $speakers = Speaker::where('user_id', $request->user()->id)
            ->withCount('contentProjects')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => SpeakerResource::collection($speakers)]);
    }

    public function store(StoreSpeakerRequest $request): JsonResponse
    {
        $speaker = new Speaker($request->validated());
        $speaker->user()->associate($request->user());
        $speaker->save();

        return response()->json(
            ['data' => new SpeakerResource($speaker->loadCount('contentProjects'))],
            201,
        );
    }

    public function show(Request $request, Speaker $speaker): JsonResponse
    {
        abort_unless($request->user()->can('view', $speaker), 404);

        return response()->json(['data' => new SpeakerResource($speaker->loadCount('contentProjects'))]);
    }

    public function update(UpdateSpeakerRequest $request, Speaker $speaker): JsonResponse
    {
        abort_unless($request->user()->can('update', $speaker), 404);

        $speaker->fill($request->validated())->save();

        return response()->json(['data' => new SpeakerResource($speaker->loadCount('contentProjects'))]);
    }
}
