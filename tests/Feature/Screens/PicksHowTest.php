<?php

use App\Actions\GrantWalletEntry;
use App\Actions\PublishSlate;
use App\Enums\ContentRating;
use App\Enums\ContestMode;
use App\Enums\LobbyFlavor;
use App\Enums\LobbyShelf;
use App\Models\User;
use App\Services\Contests\ModeEngine;
use App\Support\Voice;
use Livewire\Livewire;

/*
 * HOW THIS WORKS — the Picks area's reference screen.
 *
 * Everything on it is DERIVED. The point of these tests is that the screen
 * cannot disagree with the code that pays: rebalancing the cooler or moving
 * a room between shelves has to move this screen without anybody editing
 * it, and a table that scrolls sideways at 390px has to stay impossible.
 */

beforeEach(function () {
    $this->travelTo('2026-09-02 12:00:00');
});

it('states the cooler tiers from the constants that pay them', function () {
    $reader = pickemAdmin();

    Livewire::actingAs($reader)->test('picks-how')
        ->assertSee('The cooler holds '.GrantWalletEntry::COOLER_CAPACITY)
        ->assertSee(GrantWalletEntry::COOLER_EMPTY_AT.' or fewer')
        ->assertSee('+'.GrantWalletEntry::TOPOFF_EMPTY_CREDITS)
        ->assertSee((GrantWalletEntry::COOLER_EMPTY_AT + 1).' to '.(GrantWalletEntry::COOLER_CAPACITY - 1))
        ->assertSee('+'.GrantWalletEntry::TOPOFF_ROOM_CREDITS)
        ->assertSee(GrantWalletEntry::COOLER_CAPACITY.' or more');
});

it('marks the tier the reader is actually standing in', function () {
    /*
     * What turns a rule into an answer. Matched by the amount the grant
     * would PAY rather than by re-deriving the bands here — two ladders is
     * one ladder that will eventually disagree.
     */
    $reader = pickemStocked(pickemAdmin(), GrantWalletEntry::COOLER_CAPACITY);

    $tiers = Livewire::actingAs($reader)->test('picks-how')->instance()->cooler;

    expect(collect($tiers)->firstWhere('mine', true)['credits'])->toBe(0);

    $broke = pickemAdmin();
    $tiers = Livewire::actingAs($broke)->test('picks-how')->instance()->cooler;

    expect(collect($tiers)->firstWhere('mine', true)['credits'])->toBe(GrantWalletEntry::TOPOFF_EMPTY_CREDITS);
});

it('prices every room off the shelf and the engine, never a list', function () {
    $rooms = collect(Livewire::actingAs(pickemAdmin())->test('picks-how')->instance()->rooms)
        ->keyBy('name');

    // Every flavor, plus the flavorless House room the enum cannot hold.
    expect($rooms)->toHaveCount(count(LobbyFlavor::cases()) + 1)
        ->and($rooms['House rooms']['entry'])->toBe(0)
        ->and($rooms[LobbyFlavor::RankedAction->label()]['entry'])->toBe(LobbyShelf::Spotlight->entryCredits())
        ->and($rooms[LobbyFlavor::SecShowdown->label()]['entry'])->toBe(0)
        ->and($rooms[LobbyFlavor::TwoMinuteDrill->label()]['entry'])->toBe(0);
});

it('says no to a wager with the reason beside it, every time', function () {
    /*
     * A "no" with no reason reads as an oversight rather than a rule, and
     * the reason is DERIVED from the same three exclusions supportsTallboy()
     * applies — so a room that changes shape cannot end up with a stale
     * sentence printed beside a correct answer.
     */
    $rooms = collect(Livewire::actingAs(pickemAdmin())->test('picks-how')->instance()->rooms)
        ->keyBy('name');

    expect($rooms[LobbyFlavor::BackPorch->label()]['wager'])->toBe('No')
        ->and($rooms[LobbyFlavor::BackPorch->label()]['rule'])->toContain('Lock')
        ->and($rooms[LobbyFlavor::UpsetAlley->label()]['wager'])->toBe('No')
        ->and($rooms[LobbyFlavor::UpsetAlley->label()]['rule'])->toContain('kicker')
        ->and($rooms[LobbyFlavor::TwoMinuteDrill->label()]['wager'])->toBe('No')
        ->and($rooms[LobbyFlavor::TwoMinuteDrill->label()]['rule'])->toContain('in and out');

    foreach ($rooms as $room) {
        expect($room['rule'])->not->toBe('');
    }
});

it('answers a dynamic room honestly rather than flatly', function () {
    /*
     * Ranked Action and the conference family deal as many games as the
     * Saturday allows. A flat "yes" would be the room promising a wager it
     * refuses on the first thin week — the same lie a numbered blurb tells
     * over a short card.
     */
    $rooms = collect(Livewire::actingAs(pickemAdmin())->test('picks-how')->instance()->rooms)
        ->keyBy('name');

    expect($rooms[LobbyFlavor::RankedAction->label()]['wager'])->toBe('On a full card')
        ->and($rooms[LobbyFlavor::SecShowdown->label()]['wager'])->toBe('On a full card')
        ->and($rooms[LobbyFlavor::RankedAction->label()]['rule'])->toContain('big enough')
        // A FIXED-size room answers plainly, because its card cannot shrink.
        ->and($rooms[LobbyFlavor::UnderTheLights->label()]['wager'])->toBe('Yes')
        ->and($rooms['House rooms']['wager'])->toBe('Yes');
});

it('reads the mode rules from the one source the Lobby reads', function () {
    // Never a second explainer: ContestMode::ruleLines() is what the lobby,
    // the mode doors, the join landing and the docs all read.
    $page = Livewire::actingAs(pickemAdmin())->test('picks-how');

    foreach (ContestMode::cases() as $mode) {
        $page->assertSee($mode->label());

        foreach ($mode->ruleLines() as $line) {
            $page->assertSee($line);
        }
    }

    // ...and the shared laws, from the partial both screens include.
    $page->assertSee('every line is a half point');
});

it('never renders a sideways-scrolling table', function () {
    /*
     * THE TRAP. Thirteen rooms and three columns is a table on any desktop
     * and a scroll bar at 390px, where the design starts. One stacked card
     * per room, widening to a grid above `sm`. ChromeConsistencyTest bans
     * the class outright; this pins the intent at the screen that would
     * most obviously reach for it.
     */
    $html = Livewire::actingAs(pickemAdmin())->test('picks-how')->html();

    expect($html)->not->toContain('overflow-x-auto')
        ->and($html)->not->toContain('<table')
        // Additive above the base width, never the only place a fact lives.
        ->and($html)->toContain('sm:grid-cols-2');
});

it('speaks its own framing in every register', function () {
    foreach (['picks.how.currency', 'picks.how.cooler', 'picks.how.rooms'] as $key) {
        $pg = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::Pg]));
        $r = Voice::line($key, for: User::factory()->make(['content_rating' => ContentRating::R]));

        expect($pg)->not->toBe('')
            ->and($r)->not->toBe('')
            ->and($r)->not->toBe($pg);
    }
});

it('names both sinks with the verbs their buttons wear', function () {
    // A button reading "Crush" over rules text saying "ice down" reads as
    // two different features, so the explainer says exactly what the Lobby
    // and the pick sheet say.
    Livewire::actingAs(pickemAdmin())->test('picks-how')
        ->assertSee('Ice down')
        ->assertSee('Crush')
        ->assertSee('+'.ModeEngine::TALLBOY_SWING)
        ->assertSee(LobbyShelf::Spotlight->entryCredits().' '.Str::plural('Tallboy', LobbyShelf::Spotlight->entryCredits()).' to enter');
});

it('opens from My Picks on both views', function () {
    // The rules are looked up mid-week as readily as on a Sunday, so a
    // door that exists on only one fork is a door somebody cannot find.
    [$commissioner, , $contest] = pickemContest();
    app(PublishSlate::class)->handle($commissioner, pickemDraftSlate($contest));

    foreach (['week', 'results'] as $view) {
        Livewire::actingAs($commissioner)
            ->withQueryParams(['view' => $view])
            ->test('pickem-home')
            ->assertSee('How this works')
            ->assertSee(route('pickem.how'), escape: false);
    }
});

it('lives behind the flag, like the rest of the economy', function () {
    // Outside it there is no economy to explain, and /picks keeps its
    // coming-soon promise instead.
    config(['cfb.pickem_open' => false]);

    // EnsureFeaturesAreActive answers 400, the same door every other
    // flag-gated Picks route wears.
    $this->actingAs(User::factory()->create())->get(route('pickem.how'))->assertStatus(400);
});
