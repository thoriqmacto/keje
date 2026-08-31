<?php

namespace App\Services\Media;

use App\Enums\RenderJobStatus;
use App\Models\RenderJob;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Why a queued render has not started.
 *
 * A render that is enqueued and never picked up looks exactly like one that
 * is about to begin: status "queued", progress 0, no error. The progress bar
 * sits at zero indefinitely and nothing ever says why, which is the single
 * most confusing state this app can be in — the work was accepted, so the
 * natural assumption is that it is running slowly.
 *
 * The usual cause is a worker that is not consuming the `media` queue. The job
 * is dispatched with onQueue('media'), so a plain `queue:work` (which listens
 * to `default` only) leaves it pending forever.
 *
 * This never marks the attempt failed. The job really is still queued and will
 * run the moment a worker appears; saying otherwise would be a lie that also
 * loses the work. It only explains the wait.
 */
class RenderQueueHealth
{
    /**
     * A sentence explaining the delay, or null while the wait is still normal.
     */
    public function stallReason(?RenderJob $job): ?string
    {
        if ($job === null || $job->status !== RenderJobStatus::Queued) {
            return null;
        }

        $grace = (int) config('media.queue.stall_after_seconds');

        if ($job->created_at === null || $job->created_at->diffInSeconds(now()) < $grace) {
            return null;
        }

        $waited = $this->humanWait($job->created_at->diffInSeconds(now()));

        // With the database driver the evidence is direct: the row is still
        // sitting unreserved in the queue table, so nothing has claimed it.
        if ($this->hasUnreservedMediaJobs()) {
            return "This render has been waiting {$waited} and no worker has picked it up."
                .' The queue worker is probably not running, or is not listening to the'
                .' "media" queue. Start it with: php artisan queue:work --queue=media,default';
        }

        return "This render has been waiting {$waited} without starting."
            .' Check that the queue worker is running on the API server.';
    }

    /**
     * Is anything still pending on the media queue?
     *
     * Only meaningful for the database driver — Redis and SQS keep their own
     * state, and a failure to read either must never break the status
     * endpoint, so anything unexpected answers "no evidence".
     */
    private function hasUnreservedMediaJobs(): bool
    {
        if (config('queue.default') !== 'database') {
            return false;
        }

        try {
            return DB::table(config('queue.connections.database.table', 'jobs'))
                ->where('queue', 'media')
                ->whereNull('reserved_at')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function humanWait(int $seconds): string
    {
        if ($seconds < 120) {
            return "{$seconds} seconds";
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 120) {
            return "{$minutes} minutes";
        }

        return intdiv($minutes, 60).' hours';
    }
}
