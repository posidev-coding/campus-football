<?php

use App\Models\BrandSetting;
use App\Models\User;
use App\Support\Brand;
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

    it('turns the SDK checksum headers off, which non-AWS S3 does not implement', function () {
        // Defaults to `when_supported` since SDK 3.337, which puts
        // x-amz-checksum-crc32 on every PutObject.
        expect(config('filesystems.disks.r2.options.request_checksum_calculation'))->toBe('when_required')
            ->and(config('filesystems.disks.r2.options.response_checksum_validation'))->toBe('when_required');
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
