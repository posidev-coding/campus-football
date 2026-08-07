<?php

use App\Filament\Pages\Branding;
use App\Models\BrandSetting;
use App\Models\User;
use App\Support\Brand;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();
});

it('keeps non-admins out of the app branding page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/branding')
        ->assertForbidden();
});

it('opens on the stored brand', function () {
    BrandSetting::current()->update(['wordmark_main' => 'Fútbol']);

    Livewire::actingAs($this->admin)
        ->test(Branding::class)
        ->assertSchemaStateSet(['wordmark_main' => 'Fútbol']);
});

it('reaches the front end with one save', function () {
    /*
     * The whole reason the resolver exists: an edit here has to move the page
     * title, the lockup, the manifest and this panel's own primary color at
     * once. Three hardcoded copies is how a rebrand ends up half-done.
     */
    Livewire::actingAs($this->admin)
        ->test(Branding::class)
        ->fillForm([
            'name' => 'Campus Futbol',
            'color_lager' => '#ff8200',
            'wordmark_main' => 'Futbol',
        ])
        ->call('save')
        ->assertHasNoErrors();

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Futbol')
        ->assertSee('--color-brand-lager:#ff8200', escape: false);

    expect($this->get(route('manifest'))->json('name'))->toBe('Campus Futbol');
});

it('flushes the resolver on save, not only the cache', function () {
    /*
     * Read FIRST, so the static memo is warm, then save, then read again. A
     * test that only reads after saving passes on the value it just wrote and
     * proves nothing — the same shape as the Carbon caching bug, which only
     * ever showed itself on the second request.
     */
    expect(Brand::color('lager'))->toBe(Brand::COLORS['lager']);

    Livewire::actingAs($this->admin)
        ->test(Branding::class)
        ->fillForm(['color_lager' => '#ff8200'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Brand::color('lager'))->toBe('#ff8200');
});

it('treats a cleared field as a reset to shipped, not as an empty brand', function () {
    BrandSetting::current()->update(['wordmark_main' => 'Futbol']);

    Livewire::actingAs($this->admin)
        ->test(Branding::class)
        ->fillForm(['wordmark_main' => ''])
        ->call('save')
        ->assertHasNoErrors();

    // Stored as '' it would beat the shipped default, and the only way back
    // would be a database edit.
    expect(BrandSetting::current()->wordmark_main)->toBeNull()
        ->and(Brand::wordmark()['main'])->toBe(Brand::WORDMARK['main']);
});

it('resets every override at once', function () {
    BrandSetting::current()->update([
        'name' => 'Something else',
        'color_ink' => '#123456',
        'assets' => ['og-image' => 'brand/custom.png'],
    ]);

    Livewire::actingAs($this->admin)
        ->test(Branding::class)
        ->callAction('reset')
        ->assertHasNoActionErrors();

    expect(Brand::name())->toBe(config('app.name'))
        ->and(Brand::color('ink'))->toBe(Brand::COLORS['ink'])
        // Nothing is deleted from disk — the override is dropped and the
        // shipped file takes over again.
        ->and(Brand::asset('og-image'))->toContain('/brand/og-image.png');
});

it('offers every icon slot the resolver knows about', function () {
    // A slot the page cannot edit is a slot that can only be changed by a
    // deploy, which is the thing this page exists to avoid.
    $html = Livewire::actingAs($this->admin)->test(Branding::class)->html();

    foreach (array_keys(Brand::SHIPPED) as $key) {
        expect($html)->toContain("assets.{$key}");
    }
});
