<?php

use App\Models\Team;
use App\Models\TeamSeasonStat;
use App\Support\Remember;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
 * Remember::filled exists because a screen's season menu is built from "which
 * years have rows", and rows arrive through queued jobs long after the command
 * that started them exits. Cache::remember pinned whatever the first request
 * saw — production served a fully populated stats screen whose season menu had
 * NO options, because the menu's empty list was cached an hour before the
 * backfill drained.
 */

describe('Remember::filled', function () {
    it('does not store an empty result', function () {
        expect(Remember::filled('r:test', 60, fn () => []))->toBe([])
            ->and(Cache::has('r:test'))->toBeFalse();

        expect(Remember::filled('r:test', 60, fn () => null))->toBeNull()
            ->and(Cache::has('r:test'))->toBeFalse();
    });

    it('stores and serves a non-empty result', function () {
        $calls = 0;
        $compute = function () use (&$calls) {
            $calls++;

            return [2025, 2024];
        };

        expect(Remember::filled('r:test', 60, $compute))->toBe([2025, 2024])
            ->and(Remember::filled('r:test', 60, $compute))->toBe([2025, 2024])
            ->and($calls)->toBe(1);
    });

    it('treats an already-cached empty as a miss, so a pinned nothing heals without cache:clear', function () {
        // The production state: an empty list cached while the backfill was
        // still draining. Cache::remember would serve this for the whole TTL.
        Cache::put('r:test', [], 3600);

        expect(Remember::filled('r:test', 60, fn () => [2025]))->toBe([2025])
            ->and(Cache::get('r:test'))->toBe([2025]);
    });
});

describe('the stats season menu', function () {
    it('heals a season menu pinned empty before the backfill landed', function () {
        // Exactly the production sequence: the menu cached [] while
        // team_season_stats was empty, then the queued jobs drained.
        Cache::put('stats:years', [], 3600);

        $team = Team::factory()->create();

        TeamSeasonStat::create([
            'team_id' => $team->id, 'season_year' => 2025, 'season_type' => 2,
            'category' => 'passing', 'stats' => ['netPassingYards' => ['value' => 3000, 'display' => '3,000']],
        ]);

        $component = Livewire::test('stats');

        $component->assertSet('year', 2025);

        expect($component->get('years'))->toBe([2025]);
    });

    it('snaps a bookmarked year with no data to the newest real one', function () {
        /*
         * ?year=2026 is what a link carried over from any screen defaulting on
         * scoreboardYear() holds all summer. Unvalidated it renders "Nothing
         * published for 2026 yet." under a menu trigger reading 2025.
         */
        $team = Team::factory()->create();

        TeamSeasonStat::create([
            'team_id' => $team->id, 'season_year' => 2025, 'season_type' => 2,
            'category' => 'passing', 'stats' => ['netPassingYards' => ['value' => 3000, 'display' => '3,000']],
        ]);

        Livewire::withQueryParams(['year' => 2026])
            ->test('stats')
            ->assertSet('year', 2025);
    });
});
