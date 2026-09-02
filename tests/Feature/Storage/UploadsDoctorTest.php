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

it('fails the ACL line when the disk does not ask for no_acl', function () {
    useR2();
    config(['filesystems.disks.r2.no_acl' => false]);
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
