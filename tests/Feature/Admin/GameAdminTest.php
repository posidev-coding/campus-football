<?php

use App\Filament\Resources\Games\GameResource;
use App\Filament\Resources\Games\Pages\ListGames;
use App\Filament\Resources\Games\Pages\ViewGame;
use App\Filament\Resources\Games\RelationManagers\OddsRelationManager;
use App\Filament\Resources\Games\RelationManagers\ScoringPlaysRelationManager;
use App\Filament\Resources\Games\Widgets\GameStats;
use App\Models\Game;
use App\Models\GameOdd;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Games, read-only.
 *
 * The load-bearing test in here is the `game_drives` guard. That column was
 * 86% of the database at 306 KB per row before it moved to its own table, and
 * the whole point of the move was that a page cannot pull it by accident. A
 * DB::listen sweep is the only thing that actually proves nothing did.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();

    [$this->season, $this->week] = pickemSeasonWeek();
});

describe('the list', function () {
    it('never reads game_drives, whatever else it loads', function () {
        // Twenty games, so a per-row read would be unmissable.
        foreach (range(1, 20) as $i) {
            pickemGame($this->season, $this->week);
        }

        $touchedDrives = false;
        $queries = 0;

        DB::listen(function ($query) use (&$touchedDrives, &$queries): void {
            $queries++;

            if (str_contains($query->sql, 'game_drives')) {
                $touchedDrives = true;
            }
        });

        Livewire::actingAs($this->admin)->test(ListGames::class)->assertOk();

        expect($touchedDrives)->toBeFalse()
            // ...and the week/season labels come from one eager load rather
            // than two queries per row.
            ->and($queries)->toBeLessThan(20);
    });

    it('composes the matchup from the denormalized columns, with no joins', function () {
        $game = pickemGame($this->season, $this->week, ['name' => 'Tennessee at Alabama']);

        Livewire::actingAs($this->admin)
            ->test(ListGames::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$game])
            ->assertSee('Tennessee at Alabama');
    });

    it('says a game was not played rather than showing it 0-0', function () {
        /*
         * `games.home_score` is `unsignedTinyInteger` DEFAULT 0 and NOT NULL,
         * so a scheduled game really does hold 0-0 in the database. Printing
         * it is a real scoreline for a game nobody has played — the "never
         * write a default" rule, applied one layer up at the render.
         *
         * The column gates on hasKickedOff() rather than on a null that
         * cannot happen.
         */
        pickemGame($this->season, $this->week, [
            'completed' => false,
            'kickoff_at' => now()->addWeek(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListGames::class)
            ->assertOk()
            ->assertSee('Not played');
    });

    it('renders a game broadcast on two networks without falling over', function () {
        // `broadcasts` carries an array cast, and Filament renders an array
        // state as a LIST — one formatter call per ELEMENT, with the element.
        pickemGame($this->season, $this->week, ['broadcasts' => ['ESPN', 'SEC Network']]);

        Livewire::actingAs($this->admin)
            ->test(ListGames::class)
            ->assertOk()
            ->assertSee('ESPN, SEC Network');
    });

    it('filters through the model\'s own scopes', function () {
        // Straight through Game::completed()/inProgress()/upcoming(), so the
        // panel and the product agree on what "in progress" means.
        $final = pickemGame($this->season, $this->week, ['completed' => true]);
        $upcoming = pickemGame($this->season, $this->week, [
            'completed' => false,
            'kickoff_at' => now()->addWeek(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(ListGames::class)
            ->filterTable('state', ['value' => 'completed'])
            ->assertCanSeeTableRecords([$final])
            ->assertCanNotSeeTableRecords([$upcoming])
            ->filterTable('state', ['value' => 'upcoming'])
            ->assertCanSeeTableRecords([$upcoming])
            ->assertCanNotSeeTableRecords([$final]);
    });

    it('has no create and no edit route', function () {
        expect(GameResource::getPages())
            ->toHaveKeys(['index', 'view'])
            ->not->toHaveKey('create')
            ->not->toHaveKey('edit');
    });
});

describe('the record view', function () {
    it('renders the matchup heading with both logos and the score', function () {
        $home = Team::factory()->create(['abbreviation' => 'BAMA']);
        $away = Team::factory()->create(['abbreviation' => 'TENN']);

        $game = pickemGame($this->season, $this->week, [
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'home_score' => 21,
            'away_score' => 24,
            'completed' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ViewGame::class, ['record' => $game->getKey()])
            ->assertOk()
            ->assertSee('TENN')
            ->assertSee('BAMA')
            ->assertSee('24 – 21')
            ->assertSee('Final');
    });

    it('never reads game_drives on the record page either', function () {
        $game = pickemGame($this->season, $this->week);

        $touchedDrives = false;
        DB::listen(function ($query) use (&$touchedDrives): void {
            if (str_contains($query->sql, 'game_drives')) {
                $touchedDrives = true;
            }
        });

        Livewire::actingAs($this->admin)
            ->test(ViewGame::class, ['record' => $game->getKey()])
            ->assertOk();

        expect($touchedDrives)->toBeFalse();
    });

    it('says a number was never reported rather than rendering it as zero', function () {
        // An empty stadium and an unreported attendance are different claims,
        // and only one of them is true.
        $game = pickemGame($this->season, $this->week, ['attendance' => null, 'home_win_prob' => null]);

        Livewire::actingAs($this->admin)
            ->test(GameStats::class, ['record' => $game])
            ->assertOk()
            ->assertSee('Not reported')
            ->assertSee('Not modeled')
            ->assertSee('No line');
    });

    it('reads the closing line in preference to an earlier one', function () {
        $game = pickemGame($this->season, $this->week);
        $favorite = Team::factory()->create(['abbreviation' => 'BAMA']);

        GameOdd::create([
            'game_id' => $game->id,
            'provider_id' => 58,
            'provider' => 'ESPN BET',
            'phase' => GameOdd::OPEN,
            'spread' => -3.5,
            'favorite_team_id' => $favorite->id,
            'captured_at' => '2026-09-01 09:00:00',
        ]);
        GameOdd::create([
            'game_id' => $game->id,
            'provider_id' => 58,
            'provider' => 'ESPN BET',
            'phase' => GameOdd::CLOSE,
            'spread' => -7.5,
            'favorite_team_id' => $favorite->id,
            'captured_at' => '2026-09-05 18:00:00',
        ]);

        Livewire::actingAs($this->admin)
            ->test(GameStats::class, ['record' => $game])
            ->assertOk()
            ->assertSee('BAMA -7.5')
            ->assertSee('closing');
    });
});

describe('the relation managers', function () {
    it('shows every captured phase of the line', function () {
        $game = pickemGame($this->season, $this->week);

        GameOdd::create([
            'game_id' => $game->id,
            'provider_id' => 58,
            'provider' => 'ESPN BET',
            'phase' => GameOdd::CURRENT,
            'spread' => -6.5,
            'over_under' => 52.5,
            'favorite_team_id' => null,
            'captured_at' => '2026-09-02 09:00:00',
        ]);

        Livewire::actingAs($this->admin)
            ->test(OddsRelationManager::class, [
                'ownerRecord' => $game,
                'pageClass' => ViewGame::class,
            ])
            ->assertOk()
            ->assertSee('ESPN BET')
            ->assertSee('52.5');
    });

    it('renders scoring plays in ESPN\'s own sequence', function () {
        $game = pickemGame($this->season, $this->week);
        $team = Team::factory()->create(['abbreviation' => 'TENN']);

        foreach ([['seq' => 2, 'text' => 'Second score'], ['seq' => 1, 'text' => 'First score']] as $play) {
            $game->scoringPlays()->create([
                'team_id' => $team->id,
                'sequence' => $play['seq'],
                'period' => 1,
                'clock' => '10:00',
                'type' => 'Touchdown',
                'text' => $play['text'],
                'home_score' => 7,
                'away_score' => 0,
            ]);
        }

        Livewire::actingAs($this->admin)
            ->test(ScoringPlaysRelationManager::class, [
                'ownerRecord' => $game,
                'pageClass' => ViewGame::class,
            ])
            ->assertOk()
            ->assertSee('First score')
            ->assertSee('Second score');
    });
});
