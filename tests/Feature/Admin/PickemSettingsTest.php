<?php

use App\Actions\PublishSlate;
use App\Filament\Pages\PickemSettings;
use App\Models\PickemSetting;
use App\Models\User;
use App\Support\Cadence;
use App\Support\PickemPreflight;
use Livewire\Livewire;

/*
 * The league clock's own page — the one row of overrides where blank means
 * the shipped default. The practice window is the exception and the reason
 * this file exists: it is the only field here whose blank means OFF, and it
 * decides whether a launch's rehearsal weekends count for real.
 */

beforeEach(function () {
    $this->travelTo('2026-09-01 12:00:00');

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();
});

it('keeps non-admins off the league clock', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/pickem-settings')
        ->assertForbidden();
});

it('opens on the stored clock', function () {
    PickemSetting::current()->update(['counts_from' => '2026-09-12']);

    Livewire::actingAs($this->admin)
        ->test(PickemSettings::class)
        ->assertSchemaStateSet(['counts_from' => '2026-09-12']);
});

it('sets the practice window, and the very next publish honors it', function () {
    /*
     * Read the clock FIRST so Cadence's static memo is warm. A test that
     * only reads after saving passes on the value it just wrote and would
     * never notice the memo serving the old clock for the rest of the
     * request that changed it — the PickemSetting::saved() flush is what
     * this actually pins.
     */
    expect(Cadence::countsFrom())->toBeNull();

    Livewire::actingAs($this->admin)
        ->test(PickemSettings::class)
        ->fillForm(['counts_from' => '2026-09-12'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Cadence::countsFrom()?->toDateString())->toBe('2026-09-12');

    [$commissioner, , $contest] = pickemContest();
    $slate = pickemDraftSlate($contest);

    app(PublishSlate::class)->handle($commissioner, $slate);

    // The fixture Saturday is 9/5 — inside the window the admin just set.
    expect($slate->fresh()->exhibition)->toBeTrue();
});

it('scopes the practice window to the private groups, and widens it on request', function () {
    /*
     * The scope is the second half of the window and it is editable from
     * the same page: the founder's launch call was "private groups
     * rehearse, the rooms count", and a later launch may want both.
     */
    Livewire::actingAs($this->admin)
        ->test(PickemSettings::class)
        ->assertSchemaStateSet(['practice_includes_rooms' => false])
        ->fillForm(['counts_from' => '2026-09-12', 'practice_includes_rooms' => true])
        ->call('save')
        ->assertHasNoErrors();

    expect(Cadence::practiceIncludesRooms())->toBeTrue();

    Livewire::actingAs($this->admin)
        ->test(PickemSettings::class)
        ->fillForm(['practice_includes_rooms' => false])
        ->call('save');

    expect(Cadence::practiceIncludesRooms())->toBeFalse()
        ->and(Cadence::countsFrom()?->toDateString())->toBe('2026-09-12');
});

it('says the practice window out loud in the preflight, set or not', function () {
    /*
     * A launch that meant to rehearse and forgot to set this looks
     * identical to one that meant every week to count, so the checklist
     * states which of the two it is looking at rather than staying quiet
     * on the unset case.
     */
    $clock = fn () => collect(app(PickemPreflight::class)->checks())->keyBy('key')['settings'];

    expect($clock()['detail'])->toContain('No practice window: every slate counts.');

    PickemSetting::current()->update(['counts_from' => '2026-09-12']);

    expect($clock()['detail'])->toContain('Counting starts Sep 12, 2026')
        ->and($clock()['detail'])->toContain('earlier Saturdays publish as practice')
        // WHO the window covers, because two scopes wear the same date.
        ->and($clock()['detail'])->toContain('private groups, while public rooms count');

    PickemSetting::current()->update(['practice_includes_rooms' => true]);

    expect($clock()['detail'])->toContain('private groups and public rooms alike');
});
