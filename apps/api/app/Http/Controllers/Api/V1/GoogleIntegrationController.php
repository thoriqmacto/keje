<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\GoogleService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\GoogleConnectionResource;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Google connection management, one isolated flow per service.
 *
 * The whole OAuth flow lives on the API: the client secrets and the tokens
 * never reach Next.js. The browser is only ever redirected.
 *
 * YouTube and Drive have separate OAuth clients because Google rejects a
 * consent request carrying both products' scopes. Each service therefore gets
 * its own redirect, callback and disconnect endpoint; the shared privates
 * below hold the logic so the two flows cannot drift apart.
 */
class GoogleIntegrationController extends Controller
{
    public function __construct(
        private readonly GoogleOAuthService $oauth,
        private readonly GoogleClientFactory $clients,
    ) {}

    /** Status of both connections, for the integrations page. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'youtube' => new GoogleConnectionResource(
                    $user->googleConnectionFor(GoogleService::YouTube),
                    GoogleService::YouTube,
                    $this->clients->isConfigured(GoogleService::YouTube),
                ),
                'drive' => new GoogleConnectionResource(
                    $user->googleConnectionFor(GoogleService::Drive),
                    GoogleService::Drive,
                    $this->clients->isConfigured(GoogleService::Drive),
                ),
            ],
        ]);
    }

    // ── YouTube ─────────────────────────────────────────────────────────────

    public function redirectYouTube(Request $request): JsonResponse
    {
        return $this->startFlow($request, GoogleService::YouTube);
    }

    public function callbackYouTube(Request $request): RedirectResponse
    {
        return $this->completeFlow($request, GoogleService::YouTube);
    }

    public function destroyYouTube(Request $request): JsonResponse
    {
        return $this->endConnection($request, GoogleService::YouTube);
    }

    // ── Drive ───────────────────────────────────────────────────────────────

    public function redirectDrive(Request $request): JsonResponse
    {
        return $this->startFlow($request, GoogleService::Drive);
    }

    public function callbackDrive(Request $request): RedirectResponse
    {
        return $this->completeFlow($request, GoogleService::Drive);
    }

    public function destroyDrive(Request $request): JsonResponse
    {
        return $this->endConnection($request, GoogleService::Drive);
    }

    // ── Shared ──────────────────────────────────────────────────────────────

    /** Hand the frontend a consent URL for one service. */
    private function startFlow(Request $request, GoogleService $service): JsonResponse
    {
        if (! $this->clients->isConfigured($service)) {
            return response()->json([
                'message' => $service->label().' is not configured on the server. Set '
                    .$service->envPrefix().'_CLIENT_ID, '.$service->envPrefix().'_CLIENT_SECRET '
                    .'and '.$service->envPrefix().'_REDIRECT_URI.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'authorization_url' => $this->oauth->authorizationUrl($request->user(), $service),
            ],
        ]);
    }

    /**
     * OAuth callback for one service.
     *
     * Unauthenticated by necessity — Google sends the browser here directly.
     * The `state` parameter proves which user started the flow; it is
     * single-use and bound to this service, so a state issued for the other
     * service is rejected here.
     */
    private function completeFlow(Request $request, GoogleService $service): RedirectResponse
    {
        // config(), not env(): a cached config makes env() null at runtime,
        // which would send every OAuth callback to localhost in production.
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $target = "{$frontend}/settings/integrations";
        $key = $service->value;

        if ($request->filled('error')) {
            return redirect("{$target}?{$key}=denied");
        }

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($state === '' || $code === '') {
            return redirect("{$target}?{$key}=invalid");
        }

        $user = $this->oauth->consumeState($service, $state);

        if ($user === null) {
            // Bad, expired, replayed, or issued for the other service.
            return redirect("{$target}?{$key}=invalid_state");
        }

        try {
            $this->oauth->completeConnection($user, $service, $code);
        } catch (Throwable) {
            // The reason is logged server-side; the URL must not carry detail.
            return redirect("{$target}?{$key}=failed");
        }

        return redirect("{$target}?{$key}=connected");
    }

    /** Disconnect one service, leaving the other untouched. */
    private function endConnection(Request $request, GoogleService $service): JsonResponse
    {
        $this->oauth->disconnect($request->user(), $service);

        return response()->json(['message' => $service->label().' disconnected.']);
    }
}
