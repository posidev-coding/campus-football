<?php

use App\Enums\GamedayStatus;
use App\Filament\Resources\Gameday\Pages\ManageGameday;
use App\Models\Game;
use App\Models\GamedayWeek;
use App\Models\Season;
use App\Models\Team;
use App\Models\User;
use App\Models\Venue;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * The human with the final word.
 *
 * Modals are this panel's blind spot — a table test proves the rows render
 * while an EditAction's form and its mutateDataUsing run only when the modal
 * mounts. Both are exercised here, because the ten-second override is the
 * entire point of this screen.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();

    $this->season = Season::factory()->create([
        'year' => 2026,
        'type' => Season::REGULAR,
        'start_date' => '2026-08-24',
        'end_date' => '2026-12-14',
    ]);

    $this->lsu = Team::factory()->create(['display_name' => 'LSU Tigers']);
    $venue = Venue::create(['id' => 99001, 'name' => 'Tiger Stadium', 'city' => 'Baton Rouge', 'state' => 'LA']);

    $this->game = Game::factory()->create([
        'season_id' => $this->season->id,
        'venue_id' => $venue->id,
        'home_team_id' => $this->lsu->id,
        'kickoff_at' => '2026-09-05 19:30:00',
    ]);

    $this->week = GamedayWeek::factory()->create(['season_year' => 2026, 'saturday' => '2026-09-05']);

    $this->travelTo('2026-09-02 09:00');
});

it('lists the weeks with an unannounced one reading as unannounced', function () {
    Livewire::actingAs($this->admin)
        ->test(ManageGameday::class)
        ->assertCanSeeTableRecords([$this->week])
        ->assertSee('Not announced');
});

it('resolves a typed city into the game and confirms it in one step', function () {
    /*
     * The form asks for a CITY, never a game: picking from a dropdown of
     * several hundred is the slow, error-prone version of the same act, and
     * the resolver already turns a place and a date into the one game we hold
     * there. Typing it IS the confirmation.
     */
    Livewire::actingAs($this->admin)
        ->test(ManageGameday::class)
        ->callAction(
            TestAction::make(EditAction::class)->table($this->week),
            ['city' => 'baton rouge', 'state' => 'la'],
        )
        ->assertHasNoActionErrors();

    $week = $this->week->fresh();

    expect($week->status)->toBe(GamedayStatus::Confirmed)
        ->and($week->state)->toBe('LA')
        ->and($week->game_id)->toBe($this->game->id)
        ->and($week->team_id)->toBe($this->lsu->id)
        ->and($week->site)->toBe('Tiger Stadium')
        ->and($week->announced_at)->not->toBeNull();
});

it('saves an override our data disagrees with, but links nothing to it', function () {
    // A person overriding the data is allowed to be right. What they are not
    // allowed to get is a silent link to a game nobody is playing there.
    Livewire::actingAs($this->admin)
        ->test(ManageGameday::class)
        ->callAction(
            TestAction::make(EditAction::class)->table($this->week),
            ['city' => 'Norman', 'state' => 'OK'],
        );

    $week = $this->week->fresh();

    expect($week->status)->toBe(GamedayStatus::Confirmed)
        ->and($week->city)->toBe('Norman')
        ->and($week->game_id)->toBeNull()
        ->and($week->team_id)->toBeNull()
        ->and($week->site)->toBeNull();
});

it('confirms what the feed already proposed without retyping it', function () {
    $this->week->update([
        'status' => GamedayStatus::Proposed,
        'city' => 'Baton Rouge',
        'state' => 'LA',
        'site' => 'Tiger Stadium',
    ]);

    Livewire::actingAs($this->admin)
        ->test(ManageGameday::class)
        ->callAction(TestAction::make('confirm')->table($this->week));

    expect($this->week->fresh()->status)->toBe(GamedayStatus::Confirmed);
});

it('offers nothing to confirm on a week nobody has proposed', function () {
    Livewire::actingAs($this->admin)
        ->test(ManageGameday::class)
        ->assertActionHidden(TestAction::make('confirm')->table($this->week));
});

it('holds a confirmed week against everything the routine finds later', function () {
    /*
     * The guarantee the whole screen depends on. If a later run could
     * overwrite this, the override would be worth nothing by Thursday.
     */
    Livewire::actingAs($this->admin)
        ->test(ManageGameday::class)
        ->callAction(
            TestAction::make(EditAction::class)->table($this->week),
            ['city' => 'Norman', 'state' => 'OK'],
        );

    Http::fake(['*' => Http::response(['matchups' => [[
        'cutoffTime' => '2026-09-05T09:00:00',
        'location' => 'Baton Rouge, LA',
    ]]])]);

    $this->artisan('cfb:gameday', ['--force' => true])->assertSuccessful();

    expect($this->week->fresh()->city)->toBe('Norman')
        ->and($this->week->fresh()->status)->toBe(GamedayStatus::Confirmed);
});
