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
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
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

        /*
         * Cloudflare R2 — user uploads (brand overrides, avatars).
         *
         * Kept beside `s3` rather than replacing it, so the stock disk stays
         * available for anything genuinely on AWS. `config('cfb.upload_disk')`
         * chooses between this and `public`, which is what makes the whole
         * change one env var to undo.
         *
         * S3-compatible is not S3, and all three differences fail silently:
         *
         * - The S3 API endpoint is AUTHENTICATED. A GET against
         *   `<account>.r2.cloudflarestorage.com` without SigV4 is a 401, so
         *   AWS_URL must be the bucket's own public hostname — a custom domain
         *   bound in the Cloudflare dashboard. Leave it unset and Storage::url()
         *   happily composes an endpoint URL that every browser refuses.
         * - R2 implements no object ACLs, and REJECTS them rather than ignoring
         *   them — Laravel Cloud's own docs are explicit that setting
         *   `visibility: 'public'` fails with `NotImplemented`. Visibility is a
         *   property of the BUCKET, chosen when it is created. So neither this
         *   disk nor any FileUpload feeding it may ask for it.
         * - Since 3.337 the AWS SDK sends `x-amz-checksum-crc32` on every
         *   upload by default (`request_checksum_calculation: when_supported`,
         *   documented at S3Client.php:295 in the installed 3.390.5). That is
         *   the usual incompatibility surface for non-AWS implementations, and
         *   `when_required` costs nothing to insure against.
         *
         * `throw` is true, unlike the disks above: an upload that silently
         * returns false leaves the admin looking at a form that appears to have
         * worked and a brand that did not change.
         */
        'r2' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'options' => [
                'request_checksum_calculation' => 'when_required',
                'response_checksum_validation' => 'when_required',
            ],
            'throw' => true,
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
