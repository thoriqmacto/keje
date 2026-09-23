<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Watching the Google connections
|--------------------------------------------------------------------------
|
| Hourly, because the thing being prevented is a failed upload and an hour is
| a long time before one. It is also cheap: a token exchange plus a YouTube
| channels list (one quota unit of ten thousand) and a Drive about.get
| (free), per connection.
|
| A refresh token from a published consent screen has no expiry to count down
| to, so probing is the only way to learn it has been revoked — which is why
| this runs on a clock rather than waiting to be asked. The one genuine
| countdown, a Testing-mode grant's seven days, is warned about by the same
| run; see GoogleGrantClock.
|
| withoutOverlapping because a probe waits on Google, and a slow response must
| not stack runs on top of each other.
|
| Production needs cron to invoke `php artisan schedule:run` every minute —
| see the deployment notes in the README.
|
*/
Schedule::command('google:health')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
