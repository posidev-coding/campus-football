<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\EngagementStats;
use App\Filament\Widgets\PicksTrendChart;
use App\Filament\Widgets\TopGroupsChart;
use App\Filament\Widgets\TopTeamsChart;
use App\Filament\Widgets\UserFunnelStats;
use App\Models\Contest;
use App\Models\Group;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\Team;
use App\Models\User;
use App\Models\WalletEntry;
use App\Support\Cadence;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * The panel's front page, which until now was Filament's own account card and
 * a link to Filament's documentation.
 *
 * Every widget is tested as a CLASS, never through the dashboard's HTML —
 * widget content is not in its page's markup, so an assertion on the page
 * proves only that the page rendered.
 */

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();
});

describe('the page itself', function () {
    it('loads for an admin and 403s for everybody else', function () {
        $this->actingAs($this->admin)->get('/admin')->assertOk();

        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    });

    it('no longer ships Filament\'s own placeholder widgets', function () {
        // The account card and the "Filament info" link were the entire
        // dashboard. Both are gone from ->widgets(); what renders now is
        // discovered from app/Filament/Widgets.
        $this->actingAs($this->admin)->get('/admin')
            ->assertOk()
            ->assertDontSee('filamentphp.com', false);
    });

    it('lays out in two columns', function () {
        expect(Livewire::actingAs($this->admin)->test(Dashboard::class)->instance()->getColumns())
            ->toBe(2);
    });
});

describe('the user funnel', function () {
    it('counts each step of the funnel, and says what share of accounts it is', function () {
        User::factory()->create([
            'email_verified_at' => now(),
            'onboarded_at' => now(),
            'standalone_seen_at' => now(),
        ]);
        User::factory()->unverified()->create();

        // Three accounts: the admin from beforeEach plus these two.
        Livewire::actingAs($this->admin)
            ->test(UserFunnelStats::class)
            ->assertOk()
            ->assertSee('Accounts')
            ->assertSee('Verified')
            ->assertSee('Installed')
            ->assertSee('Textable')
            ->assertSee('% of accounts');
    });

    it('never fabricates a percentage when there is nobody to be a percentage of', function () {
        // Zero users means zero denominator. "0% of accounts" is an invented
        // number that a pilot dashboard reads as a real signal.
        User::query()->delete();

        Livewire::actingAs($this->admin)
            ->test(UserFunnelStats::class)
            ->assertOk()
            ->assertSee('can earn and play')
            ->assertDontSee('% of accounts');
    });

    it('counts textable as opted-in AND number-verified, never one of the two', function () {
        // A consented number that was never verified is one mistyped digit
        // away from being a stranger's phone.
        User::factory()->create(['sms_opt_in' => true, 'phone' => '+18655550101', 'phone_verified_at' => now()]);
        User::factory()->create(['sms_opt_in' => true, 'phone' => '+18655550102', 'phone_verified_at' => null]);

        $stats = collect(invade(new UserFunnelStats)->getStats());

        expect($stats->first(fn ($stat): bool => $stat->getLabel() === 'Textable')->getValue())->toBe('1');
    });
});

describe('engagement', function () {
    it('breaks contests down by the mode they play', function () {
        Contest::factory()->count(2)->create();
        Contest::factory()->woodshed()->create();

        Livewire::actingAs($this->admin)
            ->test(EngagementStats::class)
            ->assertOk()
            // The mode's own name first — the labels are proper nouns, and
            // "1 the woodshed" is not a sentence.
            ->assertSee('Shotgun 2')
            ->assertSee('The Woodshed 1');
    });

    it('says so plainly when no contest is running, rather than a row of zeroes', function () {
        Livewire::actingAs($this->admin)
            ->test(EngagementStats::class)
            ->assertOk()
            ->assertSee('none running yet');
    });

    it('counts picks for the Saturday this week is on', function () {
        // The pick'em week runs Tuesday through Monday, so the fixture pins
        // "now" rather than letting the day of the run decide which Saturday
        // is current.
        $this->travelTo(Carbon::parse('2026-09-02 12:00:00', config('cfb.timezone')));

        [$season, $week] = pickemSeasonWeek();
        $game = pickemGame($season, $week);
        $slate = Slate::factory()->create(['week_id' => $week->id, 'saturday' => '2026-09-05']);
        $slateGame = SlateGame::factory()->create(['slate_id' => $slate->id, 'game_id' => $game->id]);

        Pick::factory()->count(3)->create(['slate_game_id' => $slateGame->id]);

        // A pick on another Saturday must not be counted.
        $other = Slate::factory()->create(['week_id' => $week->id, 'saturday' => '2026-09-12']);
        Pick::factory()->create([
            'slate_game_id' => SlateGame::factory()->create([
                'slate_id' => $other->id,
                'game_id' => $game->id,
            ])->id,
        ]);

        expect(Cadence::currentSaturday()->toDateString())->toBe('2026-09-05');

        $stats = collect(invade(new EngagementStats)->getStats());

        expect($stats->first(fn ($stat): bool => $stat->getLabel() === 'Picks this week')->getValue())
            ->toBe('3');
    });

    it('sums the wallet from the ledger, XP and lattes together', function () {
        WalletEntry::factory()->create(['xp' => 100, 'lattes' => 1]);
        WalletEntry::factory()->create(['xp' => 25, 'lattes' => 0]);

        Livewire::actingAs($this->admin)
            ->test(EngagementStats::class)
            ->assertOk()
            ->assertSee('125')
            ->assertSee('1 Beast Lattes poured');
    });
});

describe('the top teams chart', function () {
    it('orders by follows and carries each school\'s own color', function () {
        $popular = Team::factory()->create(['abbreviation' => 'TENN', 'color' => 'FF8200']);
        $quiet = Team::factory()->create(['abbreviation' => 'VAN', 'color' => '000000']);

        foreach (User::factory()->count(3)->create() as $follower) {
            $popular->followers()->attach($follower->id, ['position' => 1]);
        }
        $quiet->followers()->attach(User::factory()->create()->id, ['position' => 2]);

        $data = invade(new TopTeamsChart)->getData();

        expect($data['labels'])->toBe(['TENN', 'VAN'])
            ->and($data['datasets'][0]['data'])->toBe([3, 1])
            ->and($data['datasets'][0]['backgroundColor'])->toBe(['#FF8200', '#000000'])
            // Position 1 is the favorite — there is no favorite_team_id column
            // anywhere, the ORDER is the model.
            ->and($data['datasets'][1]['data'])->toBe([3, 0]);
    });

    it('leaves a team nobody follows off the chart entirely', function () {
        Team::factory()->create(['abbreviation' => 'NOBODY']);

        expect(invade(new TopTeamsChart)->getData()['labels'])->toBe([]);
    });

    it('falls back to a neutral bar for a team ESPN gave us no color for', function () {
        $team = Team::factory()->create(['abbreviation' => 'GRAY', 'color' => null]);
        $team->followers()->attach(User::factory()->create()->id, ['position' => 1]);

        expect(invade(new TopTeamsChart)->getData()['datasets'][0]['backgroundColor'])
            ->toBe(['#9ca3af']);
    });

    it('caps at ten, however many teams have followers', function () {
        $user = User::factory()->create();

        foreach (range(1, 12) as $i) {
            Team::factory()->create()->followers()->attach($user->id, ['position' => $i]);
        }

        expect(invade(new TopTeamsChart)->getData()['labels'])->toHaveCount(10);
    });
});

describe('the top groups chart', function () {
    it('orders groups by how many people are in them', function () {
        $big = Group::factory()->create(['name' => 'The Big One']);
        $small = Group::factory()->create(['name' => 'Just Us']);

        $big->members()->attach(User::factory()->count(3)->create()->pluck('id'), ['role' => 'member']);
        $small->members()->attach(User::factory()->create()->id, ['role' => 'member']);

        $data = invade(new TopGroupsChart)->getData();

        expect($data['labels'])->toBe(['The Big One', 'Just Us'])
            ->and($data['datasets'][0]['data'])->toBe([3, 1]);
    });

    it('leaves an empty group off rather than drawing a zero-length bar', function () {
        Group::factory()->create(['name' => 'Nobody Joined']);

        expect(invade(new TopGroupsChart)->getData()['labels'])->toBe([]);
    });
});

describe('the picks trend', function () {
    it('plots one point per Saturday that was actually played', function () {
        [$season, $week] = pickemSeasonWeek();
        $game = pickemGame($season, $week);
        $contest = Contest::factory()->create(['season_year' => 2026]);

        $first = Slate::factory()->create([
            'contest_id' => $contest->id,
            'week_id' => $week->id,
            'saturday' => '2026-09-05',
        ]);
        $second = Slate::factory()->create([
            'contest_id' => $contest->id,
            'week_id' => $week->id,
            'saturday' => '2026-09-12',
        ]);

        Pick::factory()->count(2)->create([
            'slate_game_id' => SlateGame::factory()->create(['slate_id' => $first->id, 'game_id' => $game->id])->id,
        ]);
        Pick::factory()->create([
            'slate_game_id' => SlateGame::factory()->create(['slate_id' => $second->id, 'game_id' => $game->id])->id,
        ]);

        $data = invade(new PicksTrendChart)->getData();

        expect($data['labels'])->toBe(['Sep 5', 'Sep 12'])
            ->and($data['datasets'][0]['data'])->toBe([2, 1]);
    });

    it('leaves an unplayed Saturday ABSENT, never zero-filled', function () {
        /*
         * A zero on this line reads as "nobody picked", which is a real and
         * alarming fact. For a Saturday that has not happened it is a
         * fabricated data point wearing a real one's clothes — the same
         * mistake as writing a default when data is missing.
         */
        [$season, $week] = pickemSeasonWeek();
        $game = pickemGame($season, $week);
        $contest = Contest::factory()->create(['season_year' => 2026]);

        $played = Slate::factory()->create([
            'contest_id' => $contest->id,
            'week_id' => $week->id,
            'saturday' => '2026-09-05',
        ]);
        // Published but unplayed: a slate exists, no pick was ever made on it.
        Slate::factory()->create([
            'contest_id' => $contest->id,
            'week_id' => $week->id,
            'saturday' => '2026-09-19',
        ]);

        Pick::factory()->create([
            'slate_game_id' => SlateGame::factory()->create(['slate_id' => $played->id, 'game_id' => $game->id])->id,
        ]);

        expect(invade(new PicksTrendChart)->getData()['labels'])->toBe(['Sep 5']);
    });

    it('reads the season from the calendar, not from whatever rows exist', function () {
        // The rule the whole data layer rides on: a season exists in the
        // database months before it is played, so "the latest season" and
        // "the current season" are different questions.
        [$season, $week] = pickemSeasonWeek();
        $game = pickemGame($season, $week);

        $lastYear = Contest::factory()->create(['season_year' => 2025]);
        $slate = Slate::factory()->create([
            'contest_id' => $lastYear->id,
            'week_id' => $week->id,
            'saturday' => '2025-09-06',
        ]);
        Pick::factory()->create([
            'slate_game_id' => SlateGame::factory()->create(['slate_id' => $slate->id, 'game_id' => $game->id])->id,
        ]);

        expect(invade(new PicksTrendChart)->getData()['labels'])->toBe([]);
    });
});
