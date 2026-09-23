<?php

namespace Tests\Support;

use App\Enums\GoogleService;
use App\Services\Google\GoogleConnectionHealth;
use App\Services\Google\GoogleOAuthClassifier;
use Mockery;

/**
 * "Assume Google is answering", for tests that are about something else.
 *
 * Publishing now probes the connection before it queues anything, which is
 * the point of the feature — a dead grant is refused at the button instead of
 * after a render. The cost is that every test touching an upload endpoint
 * would otherwise try to exchange a fabricated refresh token with Google and
 * be refused, failing for a reason it was never testing.
 *
 * So the tests that care about the probe exercise the real service
 * (GoogleConnectionHealthTest), and the ones that care about locking,
 * ordering or permissions say so here instead of silently depending on a
 * network call.
 */
trait FakesGoogleHealth
{
    /** Every service reports healthy, without talking to anybody. */
    protected function assumeGoogleConnectionsWork(): void
    {
        $health = Mockery::mock(GoogleConnectionHealth::class);

        $health->shouldReceive('check')
            ->andReturnUsing(fn ($user, GoogleService $service) => $this->healthyPayload($service));

        $health->shouldReceive('lastKnown')
            ->andReturnUsing(fn ($user, GoogleService $service) => $this->healthyPayload($service));

        $health->shouldReceive('checkAll')->andReturnUsing(fn () => [
            GoogleService::YouTube->value => $this->healthyPayload(GoogleService::YouTube),
            GoogleService::Drive->value => $this->healthyPayload(GoogleService::Drive),
        ]);

        $this->instance(GoogleConnectionHealth::class, $health);
    }

    /** @return array<string, mixed> */
    private function healthyPayload(GoogleService $service): array
    {
        return [
            'service' => $service->value,
            'label' => $service->label(),
            'status' => GoogleOAuthClassifier::HEALTHY,
            'message' => 'Connected and working.',
            'guidance' => [],
            'configured' => true,
            'connected' => true,
            'checked_at' => now()->toIso8601String(),
            'failing_since' => null,
            'expires_at' => null,
            'expires_in_seconds' => null,
            'expires_in_human' => null,
        ];
    }
}
