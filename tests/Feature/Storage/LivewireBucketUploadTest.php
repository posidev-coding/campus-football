<?php

use App\Support\R2SignedUploadUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Facades\GenerateSignedUploadUrlFacade;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;

/*
 * THE UPLOAD PATH PRODUCTION ACTUALLY TAKES.
 *
 * `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=r2` is set only on the deployment,
 * and `r2` is the `s3` driver — so deployed, Livewire stops posting files
 * to its own endpoint and hands the BROWSER a pre-signed PUT straight at
 * the bucket. Every local upload takes the other branch, which is why this
 * path had never run outside production and every file failed there.
 *
 * No network and no credentials: pre-signing is string building, the same
 * discipline UploadDiskTest keeps.
 */
function useBucketTempDisk(): void
{
    $bucket = [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'auto',
        'bucket' => 'campus-football',
        'endpoint' => 'https://abc123.r2.cloudflarestorage.com',
        'url' => 'https://cdn.campusfootball.net',
    ];

    config([
        'cfb.upload_disk' => 'r2',
        'filesystems.disks.r2' => $bucket,
        // What `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=r2` says in production,
        // and what isUsingS3() reads to pick the direct-to-bucket branch.
        'livewire.temporary_file_upload.disk' => 'r2',
        /*
         * Under a test run Livewire ignores that setting and reaches for a
         * disk called `tmp-for-tests`, faking it ONLY if it does not resolve.
         * Defining it bucket-shaped is therefore the way to make the real
         * signing run here instead of against a local directory.
         */
        'filesystems.disks.tmp-for-tests' => $bucket,
    ]);

    Storage::forgetDisk('r2');
    Storage::forgetDisk('tmp-for-tests');

    /*
     * Livewire stubs this facade out for its own test suite so that nothing
     * signs a URL at all. Put the real thing back for these — they exist
     * precisely to hold what gets signed.
     */
    GenerateSignedUploadUrlFacade::swap(new R2SignedUploadUrl);
}

it('resolves the ACL-free generator through the container', function () {
    expect(app(GenerateSignedUploadUrl::class))
        ->toBeInstanceOf(R2SignedUploadUrl::class);
});

it('takes the direct-to-bucket branch when the temporary disk is the bucket', function () {
    useBucketTempDisk();

    // The branch itself, asserted rather than assumed: everything below only
    // matters because Livewire answers true here in production.
    expect(FileUploadConfiguration::isUsingS3())->toBeTrue();
});

it('asks R2 for no ACL, because R2 rejects one rather than ignoring it', function () {
    useBucketTempDisk();

    $payload = GenerateSignedUploadUrlFacade::forS3(UploadedFile::fake()->image('tr.png', 400, 400));

    /*
     * Livewire signs `x-amz-acl: private` by default, as a query parameter
     * AND a request header. config/filesystems.php has said since the disk
     * was added that no upload feeding R2 may ask for a visibility — it
     * answers `NotImplemented` and the PUT fails. This is the whole bug.
     */
    expect(strtolower(json_encode($payload)))->not->toContain('acl');
});

it('signs a PUT at the bucket, which is a cross-origin request from the app', function () {
    useBucketTempDisk();

    $payload = GenerateSignedUploadUrlFacade::forS3(UploadedFile::fake()->image('tr.png', 400, 400));

    /*
     * Held so the second half of this bug cannot be forgotten: the browser
     * PUTs to the bucket's own host, not to ours. That needs a CORS policy
     * on the bucket allowing PUT from the app's origin — configuration that
     * lives in Cloudflare, not in this repository. If this assertion ever
     * changes, the CORS policy has to change with it.
     */
    expect(parse_url($payload['url'], PHP_URL_HOST))
        ->toBe('campus-football.abc123.r2.cloudflarestorage.com');
});

it('leaves the local branch alone, which is what every developer machine uses', function () {
    config(['livewire.temporary_file_upload.disk' => null, 'filesystems.default' => 'local']);

    expect(FileUploadConfiguration::isUsingS3())->toBeFalse()
        ->and(GenerateSignedUploadUrlFacade::forLocal())->toContain('upload-file');
});
