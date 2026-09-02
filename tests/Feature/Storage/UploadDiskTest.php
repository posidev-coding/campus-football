<?php

use App\Models\BrandSetting;
use App\Models\User;
use App\Support\Brand;
use App\Support\R2Writes;
use Aws\History;
use Aws\Middleware;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Point the app at a bucket-shaped disk without needing a bucket. Credentials
 * are never used: every assertion below that touches this is about the URL, and
 * url() is string building.
 */
function useR2(): void
{
    config([
        'cfb.upload_disk' => 'r2',
        'filesystems.disks.r2.key' => 'test-key',
        'filesystems.disks.r2.secret' => 'test-secret',
        'filesystems.disks.r2.bucket' => 'test-bucket',
        'filesystems.disks.r2.endpoint' => 'https://account.r2.cloudflarestorage.com',
        'filesystems.disks.r2.url' => 'https://cdn.campusfootball.net',
    ]);
}

/**
 * Uploads move to Cloudflare R2 on Laravel Cloud, whose own filesystem does not
 * survive a deploy. One config key chooses the disk, and these hold the two
 * things that key must never break: the shipped fallback, and the URL.
 */
describe('the configurable disk', function () {
    it('serves an uploaded asset from whichever disk is configured', function () {
        /*
         * Deliberately NOT Storage::fake here. The fake REPLACES the disk
         * definition with a local one, taking the configured public URL with it
         * — so a faked disk can never prove what a real asset URL looks like.
         * Building a URL is pure string work and needs no credentials.
         */
        useR2();
        Brand::flush();

        BrandSetting::current()->update(['assets' => ['og-image' => 'brand/custom.png']]);

        expect(Brand::asset('og-image'))->toStartWith('https://cdn.campusfootball.net/brand/custom.png');
    });

    it('falls back to the SHIPPED file from the local filesystem, whatever the disk', function () {
        /*
         * The shipped brand is in git and deploys with the app. Routing it
         * through the bucket would make a stock install's favicon depend on a
         * network call to a bucket that might not even be configured — which is
         * the state every fresh clone is in.
         */
        Storage::fake('r2');
        config(['cfb.upload_disk' => 'r2']);
        Brand::flush();

        expect(Brand::asset('og-image'))->toContain('/brand/og-image.png')
            ->and(Brand::asset('og-image'))->not->toContain('cdn.campusfootball.net');

        // And the bytes still resolve, which is what the favicon route needs.
        expect(Brand::bytes('favicon-32'))->not->toBeNull();
    });

    it('reads an uploaded override back off the configured disk', function () {
        Storage::fake('r2');
        config(['cfb.upload_disk' => 'r2']);
        Brand::flush();

        Storage::disk('r2')->put('brand/favicon-32.png', 'not-really-a-png');
        BrandSetting::current()->update(['assets' => ['favicon-32' => 'brand/favicon-32.png']]);

        expect(Brand::bytes('favicon-32'))->toBe('not-really-a-png');
    });

    it('asks for no ACL, because R2 rejects one outright', function () {
        /*
         * Laravel Cloud's docs are explicit: R2 manages visibility at the
         * BUCKET level and returns NotImplemented for a per-object ACL. So
         * neither the disk nor the Filament upload feeding it may set
         * visibility — this is a config assertion because the failure only
         * appears against the real bucket, where it is a 501 on save.
         */
        expect(config('filesystems.disks.r2'))->not->toHaveKey('visibility');

        /*
         * Filament calls setVisibility($path, 'public') on save, and ONLY when
         * getVisibility() resolves to 'public' — which happens when the disk is
         * literally named `public` or when ->visibility('public') was called.
         * On any other disk it resolves to 'private' and no ACL is sent at all.
         *
         * Asserted through the framework rather than by grepping our own source,
         * because the risk being guarded is Filament changing that default under
         * us — which source text would never notice.
         */
        expect(FileUpload::make('icon')->disk('r2')->getVisibility())->toBe('private')
            ->and(FileUpload::make('icon')->disk('public')->getVisibility())->toBe('public');
    });

    it('turns the SDK checksum headers off, as the CLIENT reads it', function () {
        /*
         * Defaults to `when_supported` since SDK 3.337, which puts
         * x-amz-checksum-crc32 on every PutObject. This used to assert the
         * config key under `options` — the Flysystem adapter's per-write
         * bag, which the SDK never reads — and was green over an inert
         * setting for months. Ask the resolved client instead: it is what
         * ApplyChecksumMiddleware consults.
         */
        useR2();
        Storage::forgetDisk('r2');

        $client = Storage::disk('r2')->getClient();

        expect($client)->toBeInstanceOf(S3Client::class)
            ->and($client->getConfig('request_checksum_calculation'))->toBe('when_required')
            ->and($client->getConfig('response_checksum_validation'))->toBe('when_required');
    });

    /*
     * CFB-41's other half. R2SignedUploadUrl took the ACL off the BROWSER's
     * presigned PUT; the app's own copy-back (SetGroupIcon, the account
     * photo, Brand, Filament) still went through Flysystem's adapter, which
     * puts `ACL` on every PutObject with no option to omit it. R2 answers
     * NotImplemented and `throw => true` made that a 500 — while every test
     * here stayed green. The pin is on the WIRE: a mock handler and a
     * history middleware on the resolved client, no bucket, no network.
     */
    it('puts to R2 with no ACL and no checksum header, through the resolved client', function () {
        useR2();
        Storage::forgetDisk('r2');

        $client = Storage::disk('r2')->getClient();
        $history = new History;

        $client->getHandlerList()->setHandler(new MockHandler([new Result([])]));
        $client->getHandlerList()->appendSign(Middleware::history($history));

        Storage::disk('r2')->put('probe.txt', 'x');

        expect($history->count())->toBe(1);

        $command = $history->getLastCommand();
        $request = $history->getLastRequest();

        expect($command->getName())->toBe('PutObject')
            ->and(isset($command['ACL']))->toBeFalse()
            ->and($request->hasHeader('x-amz-acl'))->toBeFalse()
            ->and($request->hasHeader('x-amz-checksum-crc32'))->toBeFalse();
    });

    it('attaches the middleware when the disk resolves, so it survives forgetDisk', function () {
        useR2();

        Storage::forgetDisk('r2');
        expect(R2Writes::attached(Storage::disk('r2')->getClient()))->toBeTrue();

        // Again: a fresh resolution carries it again, once.
        Storage::forgetDisk('r2');
        $client = Storage::disk('r2')->getClient();

        expect(R2Writes::attached($client))->toBeTrue()
            ->and(substr_count((string) $client->getHandlerList(), 'Name: '.R2Writes::NAME))->toBe(1);

        // And a second attach on the same client replaces rather than stacks.
        R2Writes::attach($client);

        expect(substr_count((string) $client->getHandlerList(), 'Name: '.R2Writes::NAME))->toBe(1);
    });

    it('leaves a genuine AWS disk alone, and follows R2 onto a disk that never asked', function () {
        /*
         * The key is the switch and stays the one to write. The ENDPOINT is
         * the safety net for a disk whose config this repository does not
         * own — Laravel Cloud mounts a bucket under a disk name of its own,
         * where nobody can add `no_acl` to an array that is not in the tree
         * (found on 2026-09-02, when the bucket turned out to be mounted as
         * disk `app`). Harmless in that direction: R2 rejects an ACL and a
         * modern AWS bucket has them disabled, so nothing on this endpoint
         * wants the header being dropped.
         */
        $base = ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'auto', 'bucket' => 'b'];

        config([
            'filesystems.disks.real-aws' => $base + ['endpoint' => null],
            'filesystems.disks.cloud-mounted' => $base + ['endpoint' => 'https://367be3a2.r2.cloudflarestorage.com'],
        ]);

        expect(R2Writes::attached(Storage::disk('real-aws')->getClient()))->toBeFalse()
            ->and(R2Writes::attached(Storage::disk('cloud-mounted')->getClient()))->toBeTrue();

        // And the endpoint test reads the HOST, never a substring: a bucket
        // named for the suffix is not an R2 endpoint.
        expect(R2Writes::wants($base + ['endpoint' => 'https://s3.amazonaws.com', 'bucket' => 'r2.cloudflarestorage.com']))->toBeFalse()
            ->and(R2Writes::wants($base + ['endpoint' => null, 'no_acl' => true]))->toBeTrue();
    });
});

describe('avatars', function () {
    it('falls back to initials when there is no photo', function () {
        // The normal state, not the exceptional one: this column sat unused
        // from the first commit until now, so every surface already renders
        // initials and the fallback path is the proven one.
        expect(User::factory()->create(['avatar' => null])->avatarUrl())->toBeNull();
    });

    it('resolves through the configured disk once one is set', function () {
        useR2();

        $user = User::factory()->create(['avatar' => 'avatars/abc.jpg']);

        expect($user->avatarUrl())->toBe('https://cdn.campusfootball.net/avatars/abc.jpg');
    });

    it('stores an upload and replaces the previous file', function () {
        Storage::fake('public');
        config(['cfb.upload_disk' => 'public']);

        $user = User::factory()->create();

        $component = Livewire\Livewire::actingAs($user)
            ->test('account')
            ->set('photo', UploadedFile::fake()->image('me.jpg', 200, 200));

        $component->assertHasNoErrors();

        $first = $user->fresh()->avatar;
        expect($first)->not->toBeNull();
        Storage::disk('public')->assertExists($first);

        // Replacing cleans up after itself, or a user swapping photos leaves a
        // trail of orphans nothing will ever delete.
        Livewire\Livewire::actingAs($user)
            ->test('account')
            ->set('photo', UploadedFile::fake()->image('other.jpg', 200, 200));

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($user->fresh()->avatar);
    });

    it('rejects a photo over the size cap, and says so', function () {
        Storage::fake('public');
        config(['cfb.upload_disk' => 'public']);

        Livewire\Livewire::actingAs(User::factory()->create())
            ->test('account')
            ->set('photo', UploadedFile::fake()->image('huge.jpg', 2000, 2000)->size(2048))
            ->assertHasErrors(['photo' => 'max']);
    });
});
