<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Binaries
    |--------------------------------------------------------------------------
    |
    | Absolute paths to the FFmpeg toolchain on the render host. These are
    | executed through Symfony Process — never through a shell — so no
    | user-controlled value is ever interpolated into a command string.
    |
    */

    'ffmpeg_path' => env('MEDIA_FFMPEG_PATH', '/usr/bin/ffmpeg'),
    'ffprobe_path' => env('MEDIA_FFPROBE_PATH', '/usr/bin/ffprobe'),

    // Hard ceiling for a single render, in seconds. Keep below the queue
    // worker's --timeout so the worker reports the failure rather than dying.
    'render_timeout' => (int) env('MEDIA_RENDER_TIMEOUT', 7200),

    // ffprobe should answer in well under a second; a long wait means trouble.
    'probe_timeout' => (int) env('MEDIA_PROBE_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Fonts
    |--------------------------------------------------------------------------
    |
    | Templates reference fonts by logical name ("sans", "sans_bold"), so a
    | future template can swap typefaces without touching the renderer. The
    | files must exist on the render host — `php artisan media:diagnose`
    | verifies them.
    |
    */

    'fonts' => [
        'sans' => env('MEDIA_FONT_FILE', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'),
        'sans_bold' => env('MEDIA_FONT_BOLD_FILE', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'),
    ],

    /*
    | GD's FreeType API sizes text in points at 96 DPI, while FFmpeg's drawtext
    | sizes it in pixels. One pixel is therefore 0.75 points. TextLayoutService
    | applies this when measuring so the measurement matches what FFmpeg draws.
    | Only change this if measurement and render visibly disagree.
    */
    'font_point_scale' => (float) env('MEDIA_FONT_POINT_SCALE', 0.75),

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | Enforced by the upload FormRequests. PHP's upload_max_filesize /
    | post_max_size and Nginx's client_max_body_size must be at least as large
    | — see the deployment section of the README.
    |
    */

    'max_audio_mb' => (int) env('MEDIA_MAX_AUDIO_MB', 512),
    'max_image_mb' => (int) env('MEDIA_MAX_IMAGE_MB', 20),

    'audio_extensions' => ['mp3', 'mpeg', 'mpg', 'm4a', 'wav', 'aac'],
    'image_extensions' => ['jpg', 'jpeg', 'png', 'webp'],

    /*
    |--------------------------------------------------------------------------
    | Output format
    |--------------------------------------------------------------------------
    |
    | Canvas and encoding defaults for rendered video. Templates may override
    | the canvas; the encoder settings are application-owned and are never
    | influenced by user input.
    |
    */

    'video' => [
        'width' => (int) env('MEDIA_VIDEO_WIDTH', 1280),
        'height' => (int) env('MEDIA_VIDEO_HEIGHT', 720),
        'fps' => (int) env('MEDIA_VIDEO_FPS', 30),
    ],

    'encoding' => [
        'video_codec' => 'libx264',
        'profile' => 'high',
        'pixel_format' => 'yuv420p',
        'crf' => (int) env('MEDIA_VIDEO_CRF', 20),
        'preset' => env('MEDIA_VIDEO_PRESET', 'medium'),
        'audio_codec' => 'aac',
        'audio_sample_rate' => (int) env('MEDIA_AUDIO_SAMPLE_RATE', 48000),
        'audio_bitrate' => env('MEDIA_AUDIO_BITRATE', '256k'),
        // Interleaves the moov atom up front so the MP4 starts playing before
        // it has fully downloaded.
        'faststart' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Waveform
    |--------------------------------------------------------------------------
    |
    | Carried over from the original shell script. Individual templates own the
    | placement; these are the defaults a template inherits.
    |
    */

    'waveform' => [
        'width' => (int) env('MEDIA_WAVE_WIDTH', 640),
        'height' => (int) env('MEDIA_WAVE_HEIGHT', 150),
        'color' => env('MEDIA_WAVE_COLOR', 'red'),
        'mode' => env('MEDIA_WAVE_MODE', 'cline'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio normalisation
    |--------------------------------------------------------------------------
    |
    | Sprint 1 normalises container/codec/sample rate only. EBU R128 loudness
    | normalisation is wired through the renderer but OFF by default: lecture
    | audio must not be materially altered without the user asking for it.
    | A project may opt in via render_settings.loudnorm = true.
    |
    */

    'loudnorm' => [
        'enabled' => (bool) env('MEDIA_LOUDNORM_ENABLED', false),
        'i' => (float) env('MEDIA_LOUDNORM_I', -16.0),
        'tp' => (float) env('MEDIA_LOUDNORM_TP', -1.5),
        'lra' => (float) env('MEDIA_LOUDNORM_LRA', 11.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Templates
    |--------------------------------------------------------------------------
    |
    | Each template is a directory under resources/media/templates/<key>/
    | containing template.php (geometry + typography) and any static assets.
    |
    */

    'templates_path' => resource_path('media/templates'),
    'default_template' => env('MEDIA_DEFAULT_TEMPLATE', 'kajian-tematik'),

    /*
    |--------------------------------------------------------------------------
    | Render progress
    |--------------------------------------------------------------------------
    |
    | FFmpeg reports progress far faster than is worth persisting. Only write
    | to the database when the percentage moved by at least this much, or this
    | many seconds have passed.
    |
    */

    'progress' => [
        'min_percent_step' => (int) env('MEDIA_PROGRESS_PERCENT_STEP', 2),
        'min_interval_seconds' => (float) env('MEDIA_PROGRESS_INTERVAL', 1.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue health
    |--------------------------------------------------------------------------
    |
    | How long a render may sit queued before the studio stops showing a bare
    | progress bar and explains the wait instead. Long enough that a worker
    | finishing the previous render is not reported as a problem; short enough
    | that a missing worker is noticed in the same sitting.
    |
    */

    'queue' => [
        'stall_after_seconds' => (int) env('MEDIA_QUEUE_STALL_SECONDS', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Filesystem identities
    |--------------------------------------------------------------------------
    |
    | Three OS users touch the same directories: PHP-FPM writes uploads, the
    | queue worker writes renders, and the deploy user runs artisan. They meet
    | through a shared group — PHP-FPM's — which the deploy user must belong
    | to. Diagnostics compare against this name; nothing here changes any
    | permission, and no command in this app ever calls chmod or chown.
    |
    */

    'permissions' => [
        'runtime_group' => env('MEDIA_RUNTIME_GROUP', 'www-data'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Playback links
    |--------------------------------------------------------------------------
    |
    | How long a signed video URL stays valid. A <video> element cannot send a
    | bearer token, so the studio asks for a capability URL instead. Keep this
    | short — it is a bearer capability for the duration.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Local retention
    |--------------------------------------------------------------------------
    |
    | The VPS is working space, not an archive. A lecture recording can be
    | hundreds of megabytes and nothing here ever deleted itself, so disk use
    | grew with every project.
    |
    | Once the rendered MP4 is in Drive, the files that produced it have done
    | their job: they exist only to allow a re-render. Removing them is
    | therefore a real trade -- a pruned project can never be re-rendered,
    | because its source audio is gone for good. The database keeps every
    | text and metadata field, and the project points at its Drive copy.
    |
    | Nothing is pruned unless Drive confirms it holds the render.
    |
    */

    'retention' => [
        // Source audio, artwork and render scratch files, once backed up.
        'prune_sources_after_backup' => (bool) env('MEDIA_PRUNE_SOURCES_AFTER_BACKUP', true),

        // The rendered MP4 itself, once backed up.
        'prune_output_after_backup' => (bool) env('MEDIA_PRUNE_OUTPUT_AFTER_BACKUP', true),

        /*
         * The YouTube pipeline uploads the same local MP4, so deleting it the
         * moment Drive finishes would break publishing for any project not yet
         * sent to YouTube. While this is on, the MP4 survives until YouTube
         * also holds a copy. Turn it off if Drive is the only destination.
         */
        'retain_output_for_youtube' => (bool) env('MEDIA_RETAIN_OUTPUT_FOR_YOUTUBE', true),
    ],

    'stream_link_ttl_minutes' => (int) env('MEDIA_STREAM_LINK_TTL_MINUTES', 30),

];
