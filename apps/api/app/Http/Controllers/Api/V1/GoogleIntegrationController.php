<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\GoogleConnectionResource;
use App\Models\GoogleConnection;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Google connection management.
 *
 * The whole OAuth flow lives on the API: the client secret and the tokens
 * never reach Next.js. The browser is only ever redirected.
 */
class GoogleIntegrationController extends Controller
{
    public function __construct(
        private readonly GoogleOAuthService $oauth,
        private readonly GoogleClientFactory $clients,
    ) {}

    /** Current connection status for the integrations page. */
    public function show(Request $request): JsonResponse
    {
        $connection = $request->user()->googleConnection ?? new GoogleConnection;

        return response()->json(['data' => new GoogleConnectionResource($connection)]);
    }

    /** Hand the frontend a consent URL to send the user to. */
    public function redirect(Request $request): JsonResponse
    {
        if (! $this->clients->isConfigured()) {
            return response()->json([
                'message' => 'Google is not configured on the server. Set GOOGLE_CLIENT_ID, '
                    .'GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI.',
            ], 422);
        }

        return response()->json([
            'data' => ['authorization_url' => $this->oauth->authorizationUrl($request->user())],
        ]);
    }

    /**
     * OAuth callback.
     *
     * Unauthenticated by necessity — Google sends the browser here directly.
     * The `state` parameter is what proves which user started the flow, and it
     * is single-use.
     */
    public function callback(Request $request): RedirectResponse
    {
        $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $target = "{$frontend}/settings/integrations";

        if ($request->filled('error')) {
            return redirect("{$target}?google=denied");
        }

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($state === '' || $code === '') {
            return redirect("{$target}?google=invalid");
        }

        $user = $this->oauth->consumeState($state);

        if ($user === null) {
            // Bad, expired or replayed state — never proceed.
            return redirect("{$target}?google=invalid_state");
        }

        try {
            $this->oauth->completeConnection($user, $code);
        } catch (Throwable) {
            // The reason is logged server-side; the URL must not carry detail.
            return redirect("{$target}?google=failed");
        }

        return redirect("{$target}?google=connected");
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->oauth->disconnect($request->user());

        return response()->json(['message' => 'Google disconnected.']);
    }
}
