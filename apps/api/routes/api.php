<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContentProjectController;
use App\Http\Controllers\Api\V1\ContentTopicController;
use App\Http\Controllers\Api\V1\DriveCatalogController;
use App\Http\Controllers\Api\V1\GoogleIntegrationController;
use App\Http\Controllers\Api\V1\ProjectMediaController;
use App\Http\Controllers\Api\V1\ProjectPublicationController;
use App\Http\Controllers\Api\V1\ProjectRenderController;
use App\Http\Controllers\Api\V1\SpeakerController;
use App\Http\Controllers\Api\V1\YouTubeCatalogController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json([
    'ok' => true,
    'name' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

Route::prefix('v1')->group(function () {
    /*
     * Signed video delivery. Deliberately outside auth:sanctum — a <video>
     * element cannot attach a bearer token. The signature issued by
     * /media-links is the authorization, and `signed` rejects anything
     * tampered with or expired.
     */
    Route::get('/content-projects/{project}/stream', [ProjectRenderController::class, 'stream'])
        ->middleware('signed')
        ->name('content-projects.stream');

    /*
     * Google OAuth callbacks, one per service. Necessarily unauthenticated —
     * Google redirects the browser straight here — so the single-use `state`
     * parameter is what binds each callback to the user who started the flow.
     * State is service-scoped, so a YouTube state is not accepted here by the
     * Drive callback or the other way round.
     */
    Route::get('/integrations/youtube/callback', [GoogleIntegrationController::class, 'callbackYouTube'])
        ->name('google.youtube.callback');

    Route::get('/integrations/drive/callback', [GoogleIntegrationController::class, 'callbackDrive'])
        ->name('google.drive.callback');

    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);

        // Email verification — link target. Signed URL, no auth.
        Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
            ->middleware('signed')
            ->name('verification.verify');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AuthController::class, 'updateMe']);
        Route::patch('/me/password', [AuthController::class, 'updatePassword']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationEmail'])
            ->middleware('throttle:auth');

        // ── Content Studio ────────────────────────────────────────────────
        // Topics group projects into a lecture series and later map to a
        // YouTube playlist.
        Route::apiResource('topics', ContentTopicController::class)
            ->parameters(['topics' => 'topic']);

        // Reusable speakers, so the name is typed once.
        Route::apiResource('speakers', SpeakerController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->parameters(['speakers' => 'speaker']);

        Route::apiResource('content-projects', ContentProjectController::class)
            ->parameters(['content-projects' => 'project']);

        Route::prefix('content-projects/{project}')->group(function () {
            // Resolved template layout — drives the browser preview and
            // rejects text that cannot be laid out.
            Route::get('/preview', [ContentProjectController::class, 'preview']);

            // Source media. ffprobe validates these, not the file extension.
            Route::post('/audio', [ProjectMediaController::class, 'storeAudio']);
            Route::post('/background', [ProjectMediaController::class, 'storeBackground']);

            // Rendering is always queued; FFmpeg never runs in a request.
            Route::post('/render', [ProjectRenderController::class, 'store']);
            Route::get('/render-status', [ProjectRenderController::class, 'status']);

            // The rendered MP4, served only to its owner.
            Route::get('/video', [ProjectRenderController::class, 'video']);
            Route::get('/download', [ProjectRenderController::class, 'download']);

            // Short-lived signed URLs so <video> can stream with range
            // requests, which it cannot do with a bearer token.
            Route::get('/media-links', [ProjectRenderController::class, 'mediaLinks']);

            // Uploaded artwork, for the studio preview.
            Route::get('/background', [ProjectRenderController::class, 'background']);

            // Publication. Independent of each other and of the render, and
            // both explicitly triggered — rendering never publishes anything.
            Route::post('/drive', [ProjectPublicationController::class, 'drive']);
            Route::post('/youtube', [ProjectPublicationController::class, 'youtube']);
            // Retry playlist membership only — never re-uploads the video.
            Route::post('/youtube/playlist', [ProjectPublicationController::class, 'playlist']);
        });

        // Google connection status: both services in one payload.
        Route::get('/integrations/google', [GoogleIntegrationController::class, 'show']);

        // Separate lifecycles. Connecting or dropping one never touches the other.
        Route::post('/integrations/youtube/redirect', [GoogleIntegrationController::class, 'redirectYouTube']);
        Route::delete('/integrations/youtube', [GoogleIntegrationController::class, 'destroyYouTube']);

        Route::post('/integrations/drive/redirect', [GoogleIntegrationController::class, 'redirectDrive']);
        Route::delete('/integrations/drive', [GoogleIntegrationController::class, 'destroyDrive']);

        /*
         * Catalog reads from the connected accounts, so the studio can offer
         * real playlists and categories instead of asking anyone to type an
         * id. Each resource is separately retrievable and separately cached:
         * one failing call must not take the integrations page with it, and
         * /integrations/google above stays a fast local status read.
         */
        Route::prefix('/integrations/youtube')->group(function () {
            Route::get('/channel', [YouTubeCatalogController::class, 'channel']);
            Route::get('/playlists', [YouTubeCatalogController::class, 'playlists']);
            Route::get('/categories', [YouTubeCatalogController::class, 'categories']);
            Route::get('/languages', [YouTubeCatalogController::class, 'languages']);
            Route::get('/recent-uploads', [YouTubeCatalogController::class, 'recentUploads']);
            // Drops the cached catalog and re-reads it. Not a re-consent.
            Route::post('/refresh', [YouTubeCatalogController::class, 'refresh']);
        });

        Route::prefix('/integrations/drive')->group(function () {
            Route::get('/about', [DriveCatalogController::class, 'about']);
            Route::get('/backups', [DriveCatalogController::class, 'backups']);
            Route::post('/refresh', [DriveCatalogController::class, 'refresh']);
        });
    });
});
