<?php

use App\Actions\SpawnPublicContest;
use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Models\Conference;
use App\Models\Contest;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Slate;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * A CONFERENCE ROOM WEARS ITS CONFERENCE — the SEC shield on the SEC
 * Showdown — on every surface that names the room: the lobby's row and its
 * dashed "could not seat" row, the My Picks card, the switcher's menu row
 * and the clubhouse hero. The mark is the one ESPN synced onto
 * `conferences.logo`, read once per screen. A conference ESPN shipped no
 * logo for keeps the mode tile, because a picture nobody sent is not a
 * fact and a substitute would be the wrong shield on somebody's room.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
    $this->shield = 'https://a.espncdn.com/i/teamlogos/ncaa_conf/500/sec.png';
    $this->sec = Conference::factory()->create([
        'id' => 8,
        'name' => 'Southeastern Conference',
        'short_name' => 'SEC',
        'abbreviation' => 'sec',
        'logo' => $this->shield,
    ]);
});

/**
 * An SEC Showdown room on this Saturday's card, the way the lobby and My
 * Picks both read one: a public room on the week with a PUBLISHED slate on
 * the Saturday being sold. Three Saturday games make 9/5 the active Saturday.
 */
function conferenceMarksRoom(LobbyFlavor $flavor = LobbyFlavor::SecShowdown): Group
{
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 3) as $i) {
        pickemGame($season, $week);
    }

    $room = Group::factory()->room($week->id)->create(['name' => $flavor->label(), 'flavor' => $flavor->value]);
    $contest = Contest::factory()->create(['group_id' => $room->id, 'mode' => $flavor->mode()]);

    Slate::factory()->create([
        'contest_id' => $contest->id,
        'week_id' => $week->id,
        'status' => Slate::PUBLISHED,
        'published_at' => now(),
    ]);

    return $room;
}

it('answers the shield for a conference room and nothing for everyone else, off one read', function () {
    $sec = Group::factory()->lobby()->create(['flavor' => LobbyFlavor::SecShowdown->value]);
    $bigTen = Group::factory()->lobby()->create(['flavor' => LobbyFlavor::BigTenBlitz->value]);
    $house = Group::factory()->lobby()->create();
    $private = Group::factory()->create();

    DB::enableQueryLog();

    expect($sec->conferenceLogoUrl())->toBe($this->shield)
        // A conference we hold no logo for — ESPN sent none, or nobody synced it.
        ->and($bigTen->conferenceLogoUrl())->toBeNull()
        ->and($house->conferenceLogoUrl())->toBeNull()
        ->and($private->conferenceLogoUrl())->toBeNull();

    $reads = count(DB::getQueryLog());

    DB::disableQueryLog();

    // Four rooms, one map.
    expect($reads)->toBe(1);
});

it('wears the shield on the lobby row, on a white puck, in place of the mode tile', function () {
    $room = conferenceMarksRoom();

    $lobby = Livewire::actingAs(pickemAdmin())->test('lobby')->html();
    $row = (string) str($lobby)->after('wire:key="room-'.$room->id.'"')->before('wire:key=');

    expect($row)->toContain('SEC Showdown')
        ->toContain('src="'.$this->shield.'"')
        // A WHITE puck in both modes — ESPN ships no dark shield.
        ->toContain('bg-white p-1 ring-1 ring-inset ring-black/10')
        ->not->toContain(ContestMode::Classic->palette()['tile'])
        // The mode still says its name on the micro-line.
        ->toContain(ContestMode::Classic->label());
});

it('dims the shield on a dashed row, for a Saturday that could not seat the room', function () {
    [$season, $week] = pickemSeasonWeek();

    foreach (range(1, 8) as $i) {
        $game = pickemGame($season, $week);
        pickemOdd($game);
        $game->predictor()->create(['matchup_quality' => 95 - $i]);
    }

    app(SpawnPublicContest::class)->handle(ContestMode::Classic, $week);

    $lobby = Livewire::actingAs(pickemAdmin())->test('lobby')->html();
    $closed = (string) str($lobby)->after('wire:key="closed-classic-conf_sec"')->before('wire:key=');

    expect($closed)->toContain('Not enough games this Saturday')
        ->toContain('src="'.$this->shield.'"')
        ->toContain('grayscale');

    // The Big Ten has no synced logo: its dashed row keeps the mode glyph.
    $bigTen = (string) str($lobby)->after('wire:key="closed-classic-conf_b1g"')->before('wire:key=');

    expect($bigTen)->toContain('Not enough games this Saturday')
        ->not->toContain('ncaa_conf')
        ->not->toContain('grayscale');
});

it('wears the shield on the My Picks card, in the switcher and on the clubhouse hero', function () {
    $reader = pickemAdmin();
    $room = conferenceMarksRoom();
    GroupMember::factory()->create(['group_id' => $room->id, 'user_id' => $reader->id]);

    $home = Livewire::actingAs($reader)->test('pickem-home')->html();
    $switcher = (string) str($home)->before('wire:key="picks-view-week"');
    $cards = (string) str($home)->after('wire:key="picks-view-week"');

    expect($switcher)->toContain('data-group-switcher')
        ->toContain('src="'.$this->shield.'"')
        ->and($cards)->toContain('SEC Showdown')
        ->toContain('src="'.$this->shield.'"')
        ->toContain(ContestMode::Classic->label());

    $hero = (string) str(Livewire::actingAs($reader)->test('group', ['group' => $room])->html())
        ->before('wire:key="group-tab-slate"');

    expect($hero)->toContain('src="'.$this->shield.'"')
        ->toContain('bg-white p-1 ring-1 ring-inset ring-black/10')
        // And not the initials tile underneath it.
        ->not->toContain('bg-zinc-100 text-zinc-700');
});

it('keeps the mode tile when ESPN shipped no logo for the conference', function () {
    /*
     * The break-it-back case: a lookup that fell back to any picture — the
     * team-logo placeholder, a first conference with a logo — would pass
     * the tests above and put the wrong shield on a room.
     */
    $this->sec->update(['logo' => null]);

    $room = conferenceMarksRoom();

    $lobby = Livewire::actingAs(pickemAdmin())->test('lobby')->html();
    $row = (string) str($lobby)->after('wire:key="room-'.$room->id.'"')->before('wire:key=');

    expect($row)->toContain('SEC Showdown')
        ->toContain(ContestMode::Classic->palette()['tile'])
        ->not->toContain('ncaa_conf')
        ->not->toContain('<img');
});
