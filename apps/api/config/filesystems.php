<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,

            /*
             * Private, but readable by the www-data group.
             *
             * Without these keys Flysystem creates every directory 0700 and
             * leaves files at the umask default. PHP-FPM then owns a tree the
             * deploy user cannot enter even as a member of www-data, so a
             * recording that is sitting on disk reports as missing — and a
             * one-time chmod does not hold, because the next upload creates
             * 0700 directories again.
             *
             * `visibility` matters as well as `permissions`: it is what makes
             * Flysystem chmod a written file at all. Without it the file mode
             * is whatever the writing process's umask allows.
             *
             * Group access only — never world. Directories carry setgid so
             * files created inside keep the group rather than the creator's.
             * mkdir applies the umask, so the group-write bit also depends on
             * PHP-FPM and the worker running with umask 0002; see the README.
             */
            'visibility' => 'private',
            'permissions' => [
                'file' => ['private' => 0660, 'public' => 0664],
                'dir' => ['private' => 02770, 'public' => 02775],
            ],
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
