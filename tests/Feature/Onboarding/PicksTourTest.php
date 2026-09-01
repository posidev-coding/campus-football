<?php

use App\Actions\EnterPicks;
use App\Actions\GrantWalletEntry;
use App\Actions\PublishSlate;
use App\Actions\RecordUxEvent;
use App\Enums\UxSignal;
use App\Models\User;
use App\Models\WalletEntry;
use App\Support\Tours;
use App\Support\Voice;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;

/*
 * THE PICKS WALK — the economy's own guided tour.
 *
 * Not a new component: tour.blade.php already runs the spotlight, and the
 * geometry is the part with the scars on it. What is new is a step list, a
 * gate, and a SECOND COLUMN — and the second column is the whole point.
 */

beforeEach(function () {
    // The funnel counts into a REAL Redis (database 15, pinned in
    // phpunit.xml) exactly as the app does — an abstraction that only
    // differs under test is where this class of bug hides.
    Redis::connection('pulse')->flushdb();
    $this->travelTo('2026-09-02 12:00:00');
});

/** Today's funnel counters, as the rollup would read them. */
function picksTourCounts(): array
{
    return array_map('intval', (array) Redis::connection('pulse')->hgetall(
        RecordUxEvent::dayKey('2026-09-02'),
    ));
}

it('walks its own stops, not the app tour\'s', function () {
    expect(Tours::stepsFor(Tours::PICKS))->toBe(['week', 'seats', 'balance', 'room', 'how'])
        // An unknown walk costs the reader the wrong tour, never the screen.
        ->and(Tours::stepsFor('nonsense'))->toBe(Tours::WALKS[Tours::HOME]);
});

it('mounts on Picks and points at targets that are actually there', function () {
    // A real seat, so the zones the walk points at are on the page: a stop
    // with no target steps over itself, which is a silently shorter walk.
    [$reader, , $contest] = pickemContest();
    app(PublishSlate::class)->handle($reader, pickemDraftSlate($contest));

    $html = Livewire::actingAs($reader->fresh())->test('pickem-home')->html();

    expect($html)->toContain('data-guided-tour');

    // Every stop's anchor. `room` and `how` are the doors at the foot; the
    // rest are the screen's own zones. A stop with no target on the page
    // steps over itself, so a missing one is a silently shorter walk.
    foreach (['seats', 'balance', 'room', 'how'] as $key) {
        expect($html)->toContain('data-tour="'.$key.'"');
    }

    // The seats stop points at the My Groups section — the block under the
    // anchor, not the switcher menu that names the section higher up.
    expect(strpos($html, 'My Groups', strpos($html, 'data-tour="seats"')))->not->toBeFalse();
});

it('stamps its OWN column and never the economy\'s first visit', function () {
    /*
     * TWO COLUMNS, and the reason: `picks_first_seen_at` is what switches
     * the Tallboy economy on and pays the weekly top-off. If dismissing a
     * walk wrote that column, a replay from Account would re-trigger a
     * grant — and if the grant's column gated the walk, a reader who waved
     * the coach marks away would look to the economy like somebody who had
     * never turned up.
     */
    $reader = pickemAdmin();
    app(EnterPicks::class)->handle($reader);

    $seen = $reader->fresh()->picks_first_seen_at;

    expect($seen)->not->toBeNull()
        ->and($reader->fresh()->hasTouredPicks())->toBeFalse();

    Livewire::actingAs($reader->fresh())->test('tour', ['walk' => Tours::PICKS])->call('complete');

    $after = $reader->fresh();

    expect($after->hasTouredPicks())->toBeTrue()
        // The first-visit stamp did not move.
        ->and($after->picks_first_seen_at->toDateTimeString())->toBe($seen->toDateTimeString());
});

it('replays without paying the first-visit grant again', function () {
    /*
     * The failure the two columns exist to make impossible. A replay walks
     * the same stops; the top-off is keyed on the football WEEK and the
     * first visit is stamped once, so neither moves.
     */
    $reader = pickemAdmin();
    app(EnterPicks::class)->handle($reader);

    $balance = $reader->fresh()->walletTotals()['credits'];
    $rows = WalletEntry::where('user_id', $reader->id)->count();

    Livewire::actingAs($reader->fresh())->test('tour', ['walk' => Tours::PICKS])->call('complete');
    Livewire::actingAs($reader->fresh())
        ->withQueryParams(['tour' => 1])
        ->test('pickem-home')
        ->assertOk();

    expect($reader->fresh()->walletTotals()['credits'])->toBe($balance)
        ->and(WalletEntry::where('user_id', $reader->id)->count())->toBe($rows);
});

it('counts its own signal rather than a second tour_dismissed', function () {
    // A signal emitted from two places stops measuring what it is named
    // after — the funnel rule, and these two walks are two questions.
    $reader = pickemAdmin();

    Livewire::actingAs($reader)->test('tour', ['walk' => Tours::PICKS])->call('complete');

    $counted = picksTourCounts();

    expect($counted[UxSignal::PicksTourDismissed->value] ?? 0)->toBe(1)
        ->and($counted[UxSignal::TourDismissed->value] ?? 0)->toBe(0);
});

it('stamps once, however many times a walk ends', function () {
    $reader = pickemAdmin();

    Livewire::actingAs($reader)->test('tour', ['walk' => Tours::PICKS])->call('complete');
    $first = $reader->fresh()->picks_tour_completed_at;

    $this->travelTo('2026-09-03 12:00:00');
    Livewire::actingAs($reader->fresh())->test('tour', ['walk' => Tours::PICKS])->call('complete');

    expect($reader->fresh()->picks_tour_completed_at->toDateTimeString())->toBe($first->toDateTimeString())
        // ...and the counter measures readers, not round trips.
        ->and(picksTourCounts()[UxSignal::PicksTourDismissed->value] ?? 0)->toBe(1);
});

it('does not come back once it has been seen off', function () {
    $reader = pickemAdmin();

    expect(Livewire::actingAs($reader)->test('pickem-home')->instance()->showTour)->toBeTrue();

    $reader->forceFill(['picks_tour_completed_at' => now()])->save();

    expect(Livewire::actingAs($reader->fresh())->test('pickem-home')->instance()->showTour)->toBeFalse()
        // ...unless it is explicitly asked for.
        ->and(Livewire::actingAs($reader->fresh())
            ->withQueryParams(['tour' => 1])
            ->test('pickem-home')
            ->instance()->showTour)->toBeTrue();
});

it('drops the replay flag when the walk ends', function () {
    // Home's lesson: showTour short-circuits on the flag, so a replayed
    // walk would stay showing after its own last card — and the `?tour=1`
    // would restart it on the next reload.
    $reader = pickemAdmin();
    $reader->forceFill(['picks_tour_completed_at' => now()])->save();

    $page = Livewire::actingAs($reader->fresh())
        ->withQueryParams(['tour' => 1])
        ->test('pickem-home');

    expect($page->instance()->showTour)->toBeTrue();

    $page->dispatch('tour-finished');

    expect($page->instance()->showTour)->toBeFalse();
});

it('stays down outside the pick\'em flag', function () {
    // Outside it this screen is a coming-soon promise, and walking somebody
    // through an economy that is not open is a tour of nothing.
    config(['cfb.pickem_open' => false]);

    $reader = User::factory()->create();

    expect(Livewire::actingAs($reader)->test('pickem-home')->instance()->showTour)->toBeFalse();
});

it('offers its own replay beside the app tour\'s', function () {
    $reader = pickemAdmin();

    Livewire::actingAs($reader)->test('account')
        ->assertSee('Replay the tour')
        ->assertSee('Replay the Picks tour')
        ->assertSee(route('pickem.home', ['tour' => 1]), escape: false);
});

it('sends the room stop to the Lobby rather than the screen it is on', function () {
    // A button to the page you are standing on is a dead button.
    $picks = Livewire::actingAs(pickemAdmin())->test('tour', ['walk' => Tours::PICKS])->html();
    $home = Livewire::actingAs(pickemAdmin())->test('tour', ['walk' => Tours::HOME])->html();

    expect($picks)->toContain(route('pickem.lobby'))
        ->and($home)->toContain(route('pickem.home'));
});

it('names the currency and the cooler on the walk itself', function () {
    // The walk exists because the economy needed explaining; a stop about
    // the balance that never says what it buys is the promise-debt again.
    $body = Voice::line('tour.balance.body', for: pickemAdmin());

    expect($body)->toContain('Tallboy')
        ->and($body)->toContain('cooler');
});

it('leaves the seed-grant line to the app tour', function () {
    // tour.wallet.seeded is appended at the HOME walk's wallet stop, which
    // the Picks walk does not have — so the XP line cannot land twice.
    $reader = pickemAdmin();

    app(GrantWalletEntry::class)->handle(
        $reader, GrantWalletEntry::FIRST_TEAM_XP, 0,
        GrantWalletEntry::REASON_FIRST_TEAM, GrantWalletEntry::REASON_FIRST_TEAM,
    );

    $html = Livewire::actingAs($reader->fresh())->test('tour', ['walk' => Tours::PICKS])->html();

    expect($html)->not->toContain('Picking your team');
});
