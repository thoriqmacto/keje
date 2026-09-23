<?php

namespace App\Services\Google;

use App\Enums\GoogleService;
use Google\Client;
use Google\Service\Drive;
use Google\Service\YouTube;

/**
 * The cheapest call that proves a token can actually reach its API.
 *
 * Separate from GoogleConnectionHealth for one reason: it is the only part of
 * the health check that talks to Google, so isolating it is what lets the
 * rest be tested without a network. The same arrangement as FfprobeService in
 * the media tests — the interesting logic is how a result is *interpreted*,
 * and that should not need the internet to exercise.
 *
 * ── Why a call at all ───────────────────────────────────────────────────
 *
 * Exchanging the refresh token proves the grant is alive. It does not prove
 * the token can do anything: a Cloud project with the Drive API left disabled
 * hands out perfectly valid tokens that fail on first use, and that failure
 * is invisible until something tries. One call closes the gap.
 *
 * Both are chosen to be as close to free as an API call gets. A YouTube
 * channels list costs one unit of a daily ten thousand; a Drive about.get
 * costs nothing. Running this hourly is not a cost worth optimising.
 */
class GoogleApiReachability
{
    public function touch(Client $client, GoogleService $service): void
    {
        match ($service) {
            GoogleService::YouTube => (new YouTube($client))->channels
                ->listChannels('id', ['mine' => true]),
            GoogleService::Drive => (new Drive($client))->about
                ->get(['fields' => 'user/emailAddress']),
        };
    }
}
