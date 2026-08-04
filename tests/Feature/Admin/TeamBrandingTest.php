<?php

use App\Enums\HeaderStyle;
use App\Filament\Resources\Teams\Pages\ManageTeams;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\EditAction;
use Livewire\Livewire;

beforeEach(function () {
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
        ->test(ManageTeams::class)
        ->callTableAction(EditAction::class, $this->team, ['header_style' => HeaderStyle::DarkText->value])
        ->assertHasNoTableActionErrors();

    $team = $this->team->fresh();

    expect($team->header_style)->toBe(HeaderStyle::DarkText)
        ->and($team->palette()->text)->toBe('#18181b');

    // And clearing it hands control back to the ladder: white on orange.
    Livewire::actingAs($this->admin)
        ->test(ManageTeams::class)
        ->callTableAction(EditAction::class, $this->team, ['header_style' => null])
        ->assertHasNoTableActionErrors();

    expect($this->team->fresh()->palette()->text)->toBe('#ffffff');
});

it('survives an ESPN team sync without losing the curated choice', function () {
    // header_style is not in the sync payload, so a sync must never touch it.
    $this->team->update(['header_style' => HeaderStyle::White]);

    $this->team->fresh()->fill(['display_name' => 'Tennessee Volunteers', 'color' => 'ff8200'])->save();

    expect($this->team->fresh()->header_style)->toBe(HeaderStyle::White);
});
