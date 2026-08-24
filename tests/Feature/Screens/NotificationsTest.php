<?php

use App\Enums\ContentRating;
use App\Jobs\SendSlateResult;
use App\Models\GroupMember;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\StoredNotification;
use App\Models\User;
use App\Notifications\SlateSettled;
use App\Support\Navigation;
use App\Support\Voice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * THE INBOX — the only channel that reaches every reader.
 *
 * Push is dismissible and needs a subscription nobody has yet; mail is
 * refusable. The database channel is what makes a results announcement
 * survive being missed, which at launch is the difference between the
 * weekly loop landing and not.
 */

/** One stored notification, in the structured shape the channel writes. */
function inboxRow(User $user, array $overrides = []): void
{
    $user->notifications()->create(array_merge([
        'id' => (string) Str::uuid(),
        'type' => SlateSettled::class,
        'data' => [
            'kind' => 'slate-results',
            'key' => 'notify.results.lost.body',
            'replace' => ['week' => 'Week 2', 'group' => 'Vol Nation', 'points' => '14', 'place' => '3rd', 'field' => '9'],
            'url' => '/picks',
        ],
        'read_at' => null,
    ], $overrides));
}

it('needs a reader: a guest is sent to sign in', function () {
    $this->get(route('notifications'))->assertRedirect(route('login'));
});

it('renders each row in the READER\'s register, not the sender\'s', function () {
    /*
     * The rows carry a Voice key and its replacements, never rendered copy.
     * Freezing the sentence at send time would pin the register: somebody
     * who later moved to PG would keep seeing the PG-13 line in their own
     * inbox, forever, about weeks long since played.
     */
    $pg = User::factory()->create(['content_rating' => ContentRating::Pg]);
    $r = User::factory()->create(['content_rating' => ContentRating::R]);

    inboxRow($pg);
    inboxRow($r);

    $replace = ['week' => 'Week 2', 'group' => 'Vol Nation', 'points' => '14', 'place' => '3rd', 'field' => '9'];

    Livewire::actingAs($pg)->test('inbox')
        ->assertSee(Voice::line('notify.results.lost.body', $replace, for: $pg));

    Livewire::actingAs($r)->test('inbox')
        ->assertSee(Voice::line('notify.results.lost.body', $replace, for: $r))
        ->assertDontSee(Voice::line('notify.results.lost.body', $replace, for: $pg));
});

it('counts what is unread, and clears it all in one tap', function () {
    $user = User::factory()->create();

    inboxRow($user);
    inboxRow($user);
    inboxRow($user, ['read_at' => now()]);

    Livewire::actingAs($user)->test('inbox')
        ->assertSee('2 unread')
        ->call('markAllRead')
        ->assertSee('All caught up');

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('marks a row read on the way to where it points', function () {
    $user = User::factory()->create();
    inboxRow($user);

    $id = $user->notifications()->sole()->id;

    Livewire::actingAs($user)->test('inbox')
        ->call('open', $id)
        ->assertRedirect('/picks');

    expect($user->fresh()->notifications()->sole()->read_at)->not->toBeNull();
});

it('refuses to open somebody else\'s notification', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();

    inboxRow($theirs);
    $id = $theirs->notifications()->sole()->id;

    Livewire::actingAs($mine)->test('inbox')->call('open', $id);

    expect($theirs->fresh()->notifications()->sole()->read_at)->toBeNull();
});

it('says something honest when there is nothing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('inbox')
        ->assertSee('Nothing yet')
        ->assertSee(Voice::line('notify.inbox.empty', for: $user));
});

it('leaves a dated row rather than an empty one for a retired key', function () {
    // Voice::line() returns '' for an unknown key, so a row written before a
    // copy change must not render as a blank line with a chevron.
    $user = User::factory()->create();
    inboxRow($user, ['data' => ['kind' => 'x', 'key' => 'notify.gone.away', 'replace' => [], 'url' => '/picks']]);

    Livewire::actingAs($user)->test('inbox')
        ->assertSee('no longer available');
});

it('holds the inbox row the moment the sending job returns', function () {
    /*
     * Deliberately UN-faked: this is the one test that proves the database
     * channel writes in-job. SlateSettled is not ShouldQueue, so the row
     * exists synchronously when SendSlateResult::handle() returns — with a
     * queued notification this would need a worker nobody runs here, and
     * the mail would send outside ThrottleMail's budget.
     */
    $this->travelTo('2026-09-13 13:00:00');

    [, $week] = pickemSeasonWeek();
    [, $group, $contest] = pickemContest();

    $slate = Slate::factory()->create([
        'contest_id' => $contest->id,
        'week_id' => $week->id,
        'saturday' => '2026-09-12',
        'status' => Slate::SETTLED,
        'settled_at' => now(),
        'published_at' => '2026-09-08 12:00:00',
    ]);

    // Every refusable channel off: the inbox is what must land regardless.
    $reader = User::factory()->create(['pickem_notify_opt_in' => false, 'email_verified_at' => null]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $reader->id]);
    SlateEntry::factory()->create([
        'slate_id' => $slate->id, 'user_id' => $reader->id, 'final_points' => 10, 'won' => true,
    ]);

    (new SendSlateResult($slate->id, $reader->id))->handle();

    expect($reader->notifications()->count())->toBe(1)
        ->and($reader->notifications()->sole()->data['kind'])->toBe('slate-results');
});

it('gives Account its first section strip, signed in only', function () {
    // Below `sm` there is no header, so a bell would be unreachable on a
    // phone — the inbox has to be an area section to be tappable at all.
    $this->actingAs(User::factory()->create());

    $sections = collect(Navigation::areas())->firstWhere('key', 'account')['sections'];

    expect(collect($sections)->pluck('label')->all())->toBe(['Account', 'Notifications']);

    // A guest has no inbox, and a one-tab strip is chrome, not navigation.
    auth()->logout();
    expect(collect(Navigation::areas())->firstWhere('key', 'account')['sections'])->toBe([]);
});

describe('the unread dot', function () {
    it('marks the tab, the section chip and the avatar — dot only, no number', function () {
        $user = User::factory()->create();
        inboxRow($user);

        // The phone tab bar (Account tab) and the desktop avatar wrapper
        // both carry the presence dot; neither ever shows a count.
        $this->actingAs($user)->get(route('home'))
            ->assertOk()
            ->assertSee('Unread notifications')
            ->assertSee('bg-red-500');

        // The Account area's section strip marks the Notifications chip.
        $this->actingAs($user)->get(route('account'))
            ->assertOk()
            ->assertSeeInOrder(['Notifications', 'bg-red-500'], escape: false);
    });

    it('shows nothing when everything is read', function () {
        $user = User::factory()->create();
        inboxRow($user, ['read_at' => now()]);

        $this->actingAs($user)->get(route('home'))
            ->assertOk()
            ->assertDontSee('Unread notifications');
    });

    it('answers the three render sites with one indexed count', function () {
        $user = User::factory()->create();
        inboxRow($user);

        DB::enableQueryLog();
        $user->unreadNoteCount();
        $user->unreadNoteCount();
        $user->unreadNoteCount();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($count)->toBe(1)
            ->and($user->unreadNoteCount())->toBe(1);
    });

    it('rides the composite reader index, not a filesort', function () {
        // (notifiable_type, notifiable_id, read_at, created_at): the dot's
        // COUNT, the inbox ordering and Filament's badge poll all filter in
        // exactly that shape.
        $indexes = collect(Schema::getIndexes('notifications'))->pluck('name');

        expect($indexes)->toContain('notifications_reader_unread_index');
    });
});

describe('retirement', function () {
    it('prunes READ rows after ninety days, and never the unread', function () {
        // "You won Week 3" matters in October, not next August — but an
        // UNREAD result is still news to its reader, whatever its age.
        $user = User::factory()->create();

        inboxRow($user, ['read_at' => now()->subDays(91), 'created_at' => now()->subDays(120)]);
        inboxRow($user, ['created_at' => now()->subDays(120)]);
        inboxRow($user, ['read_at' => now()]);

        $this->artisan('model:prune', ['--model' => [StoredNotification::class]])
            ->assertSuccessful();

        expect($user->notifications()->count())->toBe(2)
            ->and($user->unreadNotifications()->count())->toBe(1);
    });
});
