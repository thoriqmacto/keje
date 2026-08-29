<?php

namespace App\Jobs;

use App\Enums\DriveStatus;
use App\Models\ContentProject;
use App\Services\Google\GoogleDriveService;
use App\Services\Google\GoogleNotConnectedException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Backs the rendered MP4 up to Google Drive.
 *
 * Entirely independent of the render and YouTube pipelines: whatever happens
 * here only ever touches drive_* columns.
 */
class UploadVideoToGoogleDriveJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Transient Google/network failures are worth backing off for. */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $projectId)
    {
        $this->onQueue('media');
    }

    public function handle(GoogleDriveService $drive): void
    {
        $project = ContentProject::with('topic')->find($this->projectId);

        if ($project === null) {
            return;
        }

        // Idempotency: never upload the same render twice.
        if ($project->drive_status === DriveStatus::Uploaded && filled($project->drive_file_id)) {
            return;
        }

        if (blank($project->output_path)) {
            $this->fail($project, 'There is no rendered video to back up.');

            return;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($project->output_path)) {
            $this->fail($project, 'The rendered video is no longer available.');

            return;
        }

        $project->forceFill([
            'drive_status' => DriveStatus::Uploading,
            'drive_error' => null,
        ])->save();

        try {
            $result = $drive->upload(
                user: $project->user,
                absolutePath: $disk->path($project->output_path),
                filename: $this->filename($project),
            );

            $project->forceFill([
                'drive_status' => DriveStatus::Uploaded,
                'drive_file_id' => $result['id'],
                'drive_file_name' => $result['name'],
                'drive_web_view_link' => $result['web_view_link'],
                'drive_uploaded_at' => now(),
                'drive_error' => null,
            ])->save();
        } catch (GoogleNotConnectedException $e) {
            $this->fail($project, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Drive backup failed', [
                'project_id' => $project->id,
                'exception' => $e,
            ]);

            $this->fail($project, 'The Google Drive upload failed. You can retry it.');

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        $project = ContentProject::find($this->projectId);

        if ($project !== null && $project->drive_status === DriveStatus::Uploading) {
            $this->fail($project, 'The Google Drive upload did not complete.');
        }
    }

    private function fail(ContentProject $project, string $message): void
    {
        // Only drive_* changes — the render stays valid.
        $project->forceFill([
            'drive_status' => DriveStatus::Failed,
            'drive_error' => $message,
        ])->save();
    }

    private function filename(ContentProject $project): string
    {
        $parts = array_filter([
            $project->topic?->name,
            $project->topic_sequence !== null ? "TEMA-{$project->topic_sequence}" : null,
            $project->working_title,
            $project->part_number !== null ? "PART-{$project->part_number}" : null,
        ]);

        return Str::slug(implode(' ', $parts) ?: 'keje-render').'.mp4';
    }
}
