<?php

use Illuminate\Support\Facades\Storage;

/*
 * THE DOCTOR answers what this repository cannot decide: which disk the
 * deployment resolved, whether the env names are set, and what the CLIENT
 * thinks about checksums and ACLs. Names, never values — the output lands
 * in a terminal and a support thread.
 *
 * One expectsOutputToContain per LINE: PendingCommand mocks doWrite with
 * one Mockery expectation per substring, and a line is consumed by the
 * first expectation it matches — two substrings on one line leaves the
 * second unmet and the test failing over output that is right there.
 */

it('reports a local disk and skips the bucket checks', function () {
    Storage::fake('public');
    config(['cfb.upload_disk' => 'public']);

    $this->artisan('cfb:uploads:doctor')
        ->expectsOutputToContain('public (local)')
        ->expectsOutputToContain('Livewire temp disk')
        ->expectsOutputToContain('local disk')
        ->expectsOutputToContain('all checks passing')
        ->assertExitCode(0);
});

it('reports the bucket-shaped disk: env names, checksum mode and the ACL middleware, never a value', function () {
    useR2();
    Storage::forgetDisk('r2');

    $this->artisan('cfb:uploads:doctor')
        ->expectsOutputToContain('r2 (s3)')
        ->expectsOutputToContain('AWS_URL')
        ->expectsOutputToContain('AWS_BUCKET')
        ->expectsOutputToContain('when_required')
        ->expectsOutputToContain('r2.no-acl middleware on the client')
        ->doesntExpectOutputToContain('test-secret')
        ->doesntExpectOutputToContain('test-key')
        ->doesntExpectOutputToContain('cdn.campusfootball.net')
        ->assertExitCode(0);
});

it('fails the ACL line when nothing asks for it and the endpoint is not R2', function () {
    // Both halves have to be off: the key is the switch, and an R2 endpoint
    // attaches the middleware on its own for a disk mounted by a platform
    // whose config this repository does not own.
    useR2();
    config(['filesystems.disks.r2.no_acl' => false, 'filesystems.disks.r2.endpoint' => 'https://s3.us-east-1.amazonaws.com']);
    Storage::forgetDisk('r2');

    $this->artisan('cfb:uploads:doctor')
        ->expectsOutputToContain('r2.no-acl missing')
        ->expectsOutputToContain('1 failing')
        ->assertExitCode(1);
});

it('refuses to probe outside production without --force, naming the disk and bucket', function () {
    useR2();
    Storage::forgetDisk('r2');

    $this->artisan('cfb:uploads:doctor --probe')
        ->expectsOutputToContain("refused: this would write to disk 'r2' (bucket test-bucket)")
        ->assertExitCode(1);
});

it('probes a local disk end to end under --force, and cleans up', function () {
    Storage::fake('public');
    config(['cfb.upload_disk' => 'public']);

    $this->artisan('cfb:uploads:doctor --probe --force')
        ->expectsOutputToContain('Probe: put')
        ->expectsOutputToContain('read back intact')
        ->expectsOutputToContain('Probe: delete')
        ->run();

    expect(Storage::disk('public')->files('uploads-doctor'))->toBe([]);
});

it('names the gap when the environment has a bucket the cached config missed', function () {
    /*
     * THE SHAPE LARAVEL CLOUD PRODUCED (2026-09-02): a hand-typed AWS_URL
     * present and every injected bucket name absent, because the config was
     * built before the platform attached the bucket. Reading config alone
     * says "unset" and sends somebody to re-read config/filesystems.php,
     * where the disk is perfectly correct. Reading the environment BESIDE it
     * names the actual fault in one line.
     */
    useR2();
    config(['filesystems.disks.r2.bucket' => null]);
    Storage::forgetDisk('r2');

    $_SERVER['AWS_BUCKET'] = 'fls-a2746aeb-af6e-4cc8-bca9-4d3ecc98411a';

    try {
        $this->artisan('cfb:uploads:doctor')
            ->expectsOutputToContain('the ENVIRONMENT has it, the config does not')
            ->expectsOutputToContain('never reaches the disk')
            // Names, never values, even here.
            ->doesntExpectOutputToContain('fls-a2746aeb-af6e-4cc8-bca9-4d3ecc98411a')
            ->assertExitCode(1);
    } finally {
        unset($_SERVER['AWS_BUCKET']);
    }
});

it('lists the disks this app defines, so a bucket mounted elsewhere is visibly not one', function () {
    // "The bucket is mounted as disk `app`" is a true sentence about the
    // platform and a false one about this repository, and the only way to
    // see that is to print what this repository actually has.
    useR2();
    Storage::forgetDisk('r2');

    $this->artisan('cfb:uploads:doctor')
        ->expectsOutputToContain('disks defined here:')
        ->expectsOutputToContain('r2 (s3)')
        ->assertExitCode(0);
});

it('judges the public URL without printing it, because names are the promise', function () {
    useR2();
    Storage::forgetDisk('r2');

    /*
     * A custom domain is right — the S3 endpoint is authenticated — but it
     * has to be BOUND to this bucket, and only the probe's HEAD settles it.
     * The verdict says so without echoing the domain: this report promises
     * names and never values, and the sweep beside it holds that promise.
     */
    $this->artisan('cfb:uploads:doctor')
        ->expectsOutputToContain('public URL is a custom domain')
        ->doesntExpectOutputToContain('cdn.campusfootball.net')
        ->assertExitCode(0);
});

it('refuses to probe a disk it could not even build, instead of dying of it', function () {
    /*
     * How this first ran on Cloud: three names unset, so Flysystem threw a
     * TypeError constructing the adapter and the stack trace buried the
     * report that had just explained why. A doctor may never die of the
     * disease it diagnoses.
     */
    useR2();
    config(['filesystems.disks.r2.bucket' => null]);
    Storage::forgetDisk('r2');

    // One expectation per LINE — the header's own rule: a line is consumed
    // by the first substring it matches, and the second never comes.
    $this->artisan('cfb:uploads:doctor --probe --force')
        ->expectsOutputToContain('not run — the disk above is not configured, so there is nothing to write to')
        ->assertExitCode(1);
});
