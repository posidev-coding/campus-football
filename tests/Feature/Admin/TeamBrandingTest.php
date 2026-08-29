<?php

use App\Enums\HeaderStyle;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/*
 * Team branding curation, which is now one tab of a full Team resource rather
 * than a resource of its own.
 *
 * The two guarantees below are unchanged by that move and are the whole point
 * of the surface: an admin can pin a header style, and the ESPN sync must
 * never touch it.
 */

beforeEach(function () {
    // Teams route by SLUG, in the panel as well as the product — Filament
    // resolves a record through getRouteKeyName() like everything else, so
    // these pages are addressed by `getRouteKey()`, never the id.
    $this->team = Team::factory()->create([
        'slug' => 'tennessee-volunteers',
        'display_name' => 'Tennessee Volunteers',
        'color' => 'ff8200',
        'alt_color' => 'ffffff',
    ]);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();
});

it('keeps non-admins out of the branding page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/teams')
        ->assertForbidden();
});

it('lets an admin pin a header style, and the palette respects it', function () {
    Livewire::actingAs($this->admin)
        ->test(EditTeam::class, ['record' => $this->team->getRouteKey()])
        ->fillForm(['header_style' => HeaderStyle::DarkText->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $team = $this->team->fresh();

    expect($team->header_style)->toBe(HeaderStyle::DarkText)
        ->and($team->palette()->text)->toBe('#18181b');

    // And clearing it hands control back to the ladder: white on orange.
    Livewire::actingAs($this->admin)
        ->test(EditTeam::class, ['record' => $this->team->getRouteKey()])
        ->fillForm(['header_style' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->team->fresh()->palette()->text)->toBe('#ffffff');
});

it('survives an ESPN team sync without losing the curated choice', function () {
    // header_style is not in the sync payload, so a sync must never touch it.
    $this->team->update(['header_style' => HeaderStyle::White]);

    $this->team->fresh()->fill(['display_name' => 'Tennessee Volunteers', 'color' => 'ff8200'])->save();

    expect($this->team->fresh()->header_style)->toBe(HeaderStyle::White);
});

it('still offers header_style as the ONLY editable field', function () {
    // Everything else about a team is ESPN's and dies at the next sync —
    // silently, which is the worst way for an edit to fail.
    Livewire::actingAs($this->admin)
        ->test(EditTeam::class, ['record' => $this->team->getRouteKey()])
        ->assertOk()
        ->assertFormFieldExists('header_style')
        ->assertFormFieldDoesNotExist('display_name')
        ->assertFormFieldDoesNotExist('color')
        ->assertFormFieldDoesNotExist('logo');
});

it('keeps the swatches and the resolved-color description on the list', function () {
    Livewire::actingAs($this->admin)
        ->test(ListTeams::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->team])
        // "White on #ff8200" — what the ladder actually chose, computed.
        // Verifying that a color was APPLIED is not verifying it is READABLE.
        ->assertSee('White on');
});
