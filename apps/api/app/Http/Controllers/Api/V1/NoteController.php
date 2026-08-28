<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreNoteRequest;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Example resource — safe to delete.
 *
 * Demonstrates the full pattern for a user-scoped, authenticated resource:
 * - list/create/delete, with owner-scoped authorization
 * - form request validation
 * - JSON-shaped responses
 *
 * Copy this as the template for real resources (projects, invoices, etc.).
 */
class NoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notes = Note::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get(['id', 'title', 'body', 'created_at', 'updated_at']);

        return response()->json(['data' => $notes]);
    }

    public function store(StoreNoteRequest $request): JsonResponse
    {
        $note = new Note($request->validated());
        $note->user()->associate($request->user());
        $note->save();

        return response()->json(
            ['data' => $note->only(['id', 'title', 'body', 'created_at', 'updated_at'])],
            201,
        );
    }

    public function destroy(Request $request, Note $note): JsonResponse
    {
        // Owner-only. Respond 404 (not 403) so foreign resources are indistinguishable.
        abort_unless($note->user_id === $request->user()->id, 404);

        $note->delete();

        return response()->json(null, 204);
    }
}
