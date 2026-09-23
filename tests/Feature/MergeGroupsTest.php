<?php

use App\Actions\MergeGroups;
use App\Enums\ContentRating;
use App\Enums\ContestMode;
use App\Exceptions\MergeBlocked;
use App\Models\Contest;
use App\Models\ConversationPost;
use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\GroupMember;
use App\Models\Pick;
use App\Models\Slate;
use App\Models\SlateEntry;
use App\Models\SlateGame;
use App\Models\User;
use App\Models\WalletEntry;
use App\Notifications\GroupModeChanged;
use App\Notifications\GroupsMerged;
use App\Support\Brand;
use App\Support\Voice;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use NotificationChannels\WebPush\WebPushChannel;

/*
 * FOLDING PRIVATE GROUPS INTO ONE — the house's lever, and an irreversible
 * one: the folded groups are deleted with every slate, pick and Talk post
 * they held. So every guard refuses with NOTHING written, the survivor's
 * season table can start over for everybody, and every member of the
 * survivor hears exactly what happened to them — in their own register.
 */

/**
 * The founder's merge in miniature. The survivor is run by its
 * commissioner, with one original member, a settled week and next week's
 * draft. "Fourth and Long" folds in: run by Ana, with Ben, a settled week
 * whose featured game points back at its own slate, picks, an invite, a
 * Talk post and an icon. "Pick Six Society" folds in too: run by Cam, with
 * unverified Dee, and Olive, who already sits in the survivor.
 *
 * @return array<string, mixed>
 */
function mergeFixture(): array
{
    [$season, $week] = pickemSeasonWeek();

    [$boss, $survivor, $contest] = pickemContest(ContestMode::Classic);
    $boss->update(['first_name' => 'Bo']);
    $survivor->update(['name' => 'Goalpost Salvage Co.', 'code' => 'WDSQVFOX']);

    $olive = User::factory()->create(['first_name' => 'Olive']);
    GroupMember::factory()->create(['group_id' => $survivor->id, 'user_id' => $olive->id]);

    $survivorWeek = Slate::factory()->create([
        'contest_id' => $contest->id, 'week_id' => $week->id, 'saturday' => '2026-08-29',
        'status' => Slate::SETTLED, 'published_at' => '2026-08-26 12:00:00', 'settled_at' => '2026-08-30 17:00:00',
    ]);
    SlateEntry::factory()->create(['slate_id' => $survivorWeek->id, 'user_id' => $olive->id, 'final_points' => 60, 'won' => true]);
    ConversationPost::factory()->create(['topic_id' => $survivor->id, 'user_id' => $olive->id]);

    $draft = pickemDraftSlate($contest);

    $ana = User::factory()->create(['first_name' => 'Ana']);
    $ben = User::factory()->create(['first_name' => 'Ben']);
    $fourth = Group::factory()->create(['name' => 'Fourth and Long', 'code' => 'FOURTHLG', 'icon' => 'groups/fourth.png']);
    GroupMember::factory()->commissioner()->create(['group_id' => $fourth->id, 'user_id' => $ana->id]);
    GroupMember::factory()->create(['group_id' => $fourth->id, 'user_id' => $ben->id]);
    $fourthContest = Contest::factory()->create(['group_id' => $fourth->id]);

    $fourthWeek = Slate::factory()->create([
        'contest_id' => $fourthContest->id, 'week_id' => $week->id, 'saturday' => '2026-08-29',
        'status' => Slate::SETTLED, 'published_at' => '2026-08-26 12:00:00', 'settled_at' => '2026-08-30 17:00:00',
    ]);
    $fourthGame = SlateGame::factory()->create(['slate_id' => $fourthWeek->id, 'game_id' => pickemGame($season, $week)->id]);
    $fourthWeek->update(['tiebreaker_slate_game_id' => $fourthGame->id, 'tiebreaker_metric' => 'combined_points']);
    SlateEntry::factory()->create(['slate_id' => $fourthWeek->id, 'user_id' => $ben->id, 'final_points' => 10]);
    Pick::factory()->won(10)->create(['slate_game_id' => $fourthGame->id, 'user_id' => $ben->id]);
    GroupInvite::factory()->create(['group_id' => $fourth->id, 'inviter_id' => $ana->id]);
    ConversationPost::factory()->create(['topic_id' => $fourth->id, 'user_id' => $ben->id]);

    $cam = User::factory()->create(['first_name' => 'Cam']);
    $dee = User::factory()->unverified()->create(['first_name' => 'Dee']);
    $pickSix = Group::factory()->create(['name' => 'Pick Six Society', 'code' => 'PICKSIXS']);
    GroupMember::factory()->commissioner()->create(['group_id' => $pickSix->id, 'user_id' => $cam->id]);
    GroupMember::factory()->create(['group_id' => $pickSix->id, 'user_id' => $dee->id]);
    GroupMember::factory()->create(['group_id' => $pickSix->id, 'user_id' => $olive->id]);
    Contest::factory()->create(['group_id' => $pickSix->id]);

    return compact(
        'boss', 'survivor', 'contest', 'olive', 'survivorWeek', 'draft',
        'ana', 'ben', 'fourth', 'fourthContest', 'fourthWeek', 'fourthGame',
        'cam', 'dee', 'pickSix',
    );
}

/** Both folding groups into the survivor, as the founder will run it. */
function mergeAll(array $f, ?ContestMode $mode = ContestMode::Woodshed, bool $fresh = true): array
{
    return app(MergeGroups::class)->handle($f['survivor'], collect([$f['fourth'], $f['pickSix']]), $mode, $fresh);
}

it('seats every mover once, as a plain member, under the survivor\'s own commissioner', function () {
    Notification::fake();
    $f = mergeFixture();

    mergeAll($f);

    $seats = GroupMember::query()->where('group_id', $f['survivor']->id)->get()->keyBy('user_id');

    // Bo and Olive stayed; Ana, Ben, Cam and Dee moved — Olive once, not twice.
    expect($seats)->toHaveCount(6)
        ->and($seats[$f['boss']->id]->role)->toBe(GroupMember::COMMISSIONER)
        ->and($seats[$f['ana']->id]->role)->toBe(GroupMember::MEMBER)
        ->and($seats[$f['cam']->id]->role)->toBe(GroupMember::MEMBER)
        ->and($seats->has($f['dee']->id))->toBeTrue()
        ->and(GroupMember::query()->where('user_id', $f['olive']->id)->count())->toBe(1);
});

it('deletes the folded groups with everything only they held, and nothing else', function () {
    Notification::fake();
    Storage::fake(config('cfb.upload_disk'));
    Storage::disk(config('cfb.upload_disk'))->put('groups/fourth.png', 'png');

    $f = mergeFixture();
    $earned = WalletEntry::factory()->create(['user_id' => $f['ben']->id, 'xp' => 40]);
    $foldedIds = [$f['fourth']->id, $f['pickSix']->id];

    mergeAll($f);

    // The folded side is gone, down to the slate whose featured game points
    // back at it — the one cycle the cascade has to be walked around.
    expect(Group::query()->whereKey($foldedIds)->exists())->toBeFalse()
        ->and(Contest::query()->whereIn('group_id', $foldedIds)->exists())->toBeFalse()
        ->and(Slate::query()->whereKey($f['fourthWeek']->id)->exists())->toBeFalse()
        ->and(SlateGame::query()->whereKey($f['fourthGame']->id)->exists())->toBeFalse()
        ->and(SlateEntry::query()->where('slate_id', $f['fourthWeek']->id)->exists())->toBeFalse()
        ->and(Pick::query()->where('user_id', $f['ben']->id)->exists())->toBeFalse()
        ->and(GroupInvite::query()->whereIn('group_id', $foldedIds)->exists())->toBeFalse()
        ->and(ConversationPost::query()->where('topic_type', 'group')->whereIn('topic_id', $foldedIds)->exists())->toBeFalse();

    Storage::disk(config('cfb.upload_disk'))->assertMissing('groups/fourth.png');

    // What the people earned, and everything the survivor held, stays.
    expect(WalletEntry::query()->whereKey($earned->id)->exists())->toBeTrue()
        ->and(Slate::query()->whereKey($f['survivorWeek']->id)->exists())->toBeTrue()
        ->and(SlateEntry::query()->where('slate_id', $f['survivorWeek']->id)->exists())->toBeTrue()
        ->and(ConversationPost::query()->where('topic_id', $f['survivor']->id)->exists())->toBeTrue();
});

it('switches the survivor\'s mode for the season and resets next week\'s draft, announced by its own note', function () {
    Notification::fake();
    $f = mergeFixture();

    mergeAll($f);

    $contest = $f['contest']->fresh();

    expect($contest->mode)->toBe(ContestMode::Woodshed)
        ->and($contest->mode_changed_at)->not->toBeNull()
        ->and($f['draft']->fresh()->games()->count())->toBe(0);

    // The merge's own note carries the change; the commissioner's would be
    // a second email about the same decision.
    Notification::assertSentTimes(GroupModeChanged::class, 0);
});

it('overrides a mode change the survivor already spent — the house is not the commissioner', function () {
    Notification::fake();
    $f = mergeFixture();
    $f['contest']->update(['mode_changed_at' => '2026-08-20 12:00:00']);

    $plan = app(MergeGroups::class)->plan($f['survivor'], collect([$f['fourth']]), ContestMode::Woodshed, false);
    expect($plan['pivot']['overrides'])->toBeTrue()
        ->and($plan['blockers'])->toBe([]);

    app(MergeGroups::class)->handle($f['survivor'], collect([$f['fourth']]), ContestMode::Woodshed, false);

    expect($f['contest']->fresh()->mode)->toBe(ContestMode::Woodshed);
});

it('stamps nothing and resets nothing when the survivor already plays the mode', function () {
    Notification::fake();
    $f = mergeFixture();
    $f['contest']->update(['mode' => ContestMode::Woodshed]);
    $games = $f['draft']->games()->count();

    app(MergeGroups::class)->handle($f['survivor'], collect([$f['fourth']]), ContestMode::Woodshed, false);

    expect($f['contest']->fresh()->mode_changed_at)->toBeNull()
        ->and($f['draft']->fresh()->games()->count())->toBe($games);

    Notification::assertSentTo($f['boss'], GroupsMerged::class, fn (GroupsMerged $note) => ! $note->pivoted);
});

it('starts the season table over for everybody on a fresh ledger, and leaves it alone otherwise', function (bool $fresh) {
    Notification::fake();
    $f = mergeFixture();

    mergeAll($f, fresh: $fresh);

    // The season table's own join: settled, and not an exhibition.
    $counted = SlateEntry::query()
        ->join('slates', 'slates.id', '=', 'slate_entries.slate_id')
        ->where('slates.contest_id', $f['contest']->id)
        ->where('slates.status', Slate::SETTLED)
        ->where('slates.exhibition', false)
        ->count();

    expect($f['survivorWeek']->fresh()->exhibition)->toBe($fresh)
        ->and($counted)->toBe($fresh ? 0 : 1)
        // Next week's slate is not a settled week, and is never re-marked.
        ->and($f['draft']->fresh()->exhibition)->toBeFalse();
})->with(['fresh' => true, 'keep' => false]);

it('refuses while a week is in flight anywhere in the set, with nothing written and nobody told', function (string $where, string $status) {
    Notification::fake();
    [, $week] = pickemSeasonWeek();
    $f = mergeFixture();

    $contest = $where === 'survivor' ? $f['contest'] : $f['fourthContest'];
    Slate::factory()->create([
        'contest_id' => $contest->id, 'week_id' => $week->id, 'saturday' => '2026-09-12',
        'status' => $status, 'published_at' => '2026-09-09 12:00:00',
    ]);

    expect(fn () => mergeAll($f))->toThrow(MergeBlocked::class);

    expect(Group::query()->whereKey([$f['fourth']->id, $f['pickSix']->id])->count())->toBe(2)
        ->and(GroupMember::query()->where('group_id', $f['survivor']->id)->count())->toBe(2)
        ->and($f['contest']->fresh()->mode)->toBe(ContestMode::Classic)
        ->and($f['survivorWeek']->fresh()->exhibition)->toBeFalse();

    Notification::assertNothingSent();
})->with([
    'a published week in the survivor' => ['survivor', Slate::PUBLISHED],
    'a preliminary week in a folding group' => ['folding', Slate::PRELIM],
]);

it('refuses a public contest, a survivor folding into itself, and a group named twice', function () {
    Notification::fake();
    $f = mergeFixture();
    $merge = app(MergeGroups::class);
    $room = Group::factory()->lobby()->create(['name' => 'House Room']);

    expect(fn () => $merge->handle($f['survivor'], collect([$room]), ContestMode::Woodshed, true))->toThrow(MergeBlocked::class)
        ->and(fn () => $merge->handle($f['survivor'], collect([$f['survivor']]), ContestMode::Woodshed, true))->toThrow(MergeBlocked::class)
        ->and(fn () => $merge->handle($f['survivor'], collect([$f['fourth'], $f['fourth']]), ContestMode::Woodshed, true))->toThrow(MergeBlocked::class);

    expect(Group::query()->whereKey([$f['fourth']->id, $room->id])->count())->toBe(2);
    Notification::assertNothingSent();
});

it('refuses a survivor with no contest this season, or with nobody to run it', function () {
    Notification::fake();
    $f = mergeFixture();
    $merge = app(MergeGroups::class);

    GroupMember::query()->where('group_id', $f['survivor']->id)->update(['role' => GroupMember::MEMBER]);

    expect(fn () => $merge->handle($f['survivor'], collect([$f['fourth']]), ContestMode::Woodshed, true))
        ->toThrow(MergeBlocked::class);

    GroupMember::query()->where('group_id', $f['survivor']->id)->where('user_id', $f['boss']->id)->update(['role' => GroupMember::COMMISSIONER]);
    $f['contest']->update(['season_year' => 2025]);

    expect(fn () => $merge->handle($f['survivor'], collect([$f['fourth']]), ContestMode::Woodshed, true))
        ->toThrow(MergeBlocked::class);

    expect(Group::query()->whereKey($f['fourth']->id)->exists())->toBeTrue();
});

it('tells every member of the survivor exactly once, with what is true for them', function () {
    Notification::fake();
    $f = mergeFixture();

    mergeAll($f);

    Notification::assertSentTimes(GroupsMerged::class, 6);

    foreach (['boss', 'olive', 'ana', 'ben', 'cam', 'dee'] as $who) {
        Notification::assertSentToTimes($f[$who], GroupsMerged::class, 1);
    }

    Notification::assertSentTo($f['ben'], GroupsMerged::class, fn (GroupsMerged $note) => $note->variant === GroupsMerged::MOVED
        && $note->former === ['Fourth and Long']
        && $note->ran === []
        && ! $note->runsIt
        && $note->pivoted
        && $note->freshStart
        && $note->count === 2
        && $note->size === 15
        && $note->mode === ContestMode::Woodshed->value);

    // Ana ran the group she is leaving, and is thanked for it by name.
    Notification::assertSentTo($f['ana'], GroupsMerged::class, fn (GroupsMerged $note) => $note->variant === GroupsMerged::MOVED
        && $note->ran === ['Fourth and Long']);

    // Olive stayed, but a group she also sat in is gone.
    Notification::assertSentTo($f['olive'], GroupsMerged::class, fn (GroupsMerged $note) => $note->variant === GroupsMerged::STAYED
        && $note->former === ['Pick Six Society']);

    // The survivor's commissioner hears that the next slate is theirs.
    Notification::assertSentTo($f['boss'], GroupsMerged::class, fn (GroupsMerged $note) => $note->variant === GroupsMerged::STAYED
        && $note->runsIt
        && $note->former === []);
});

it('mails only a proven address, writes every inbox, and pushes where a device said yes', function () {
    $note = mergedNote();

    $verified = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    $subscribed = User::factory()->create();
    $subscribed->updatePushSubscription('https://push.example.test/send/merged', 'key', 'token');

    expect($note->via($verified))->toBe(['database', 'mail'])
        ->and($note->via($unverified))->toBe(['database'])
        ->and($note->via($subscribed))->toBe(['database', 'mail', WebPushChannel::class]);
});

it('speaks the reader\'s own register with nobody signed in, and states the rules from the one source', function () {
    // Deliberately no actingAs: inside the queued job there is no signed-in
    // user, and Voice falls back to PG-13 for everybody if `for:` is lost.
    $note = mergedNote();
    $pg = User::factory()->create(['content_rating' => ContentRating::Pg]);
    $r = User::factory()->create(['content_rating' => ContentRating::R]);

    $mild = $note->toMail($pg);
    $spicy = $note->toMail($r);

    expect($mild->subject)->toBe('Fourth and Long is now part of Goalpost Salvage Co. — '.Brand::name())
        ->and($mild->introLines[0])->toContain('Fourth and Long')
        ->and($mild->introLines[0])->not->toBe($spicy->introLines[0])
        ->and(implode(' ', $mild->introLines))->not->toMatch('/:[a-z_]+/')
        ->and($mild->actionUrl)->toBe('https://campusfootball.test/groups/7');

    foreach (ContestMode::Woodshed->ruleLines(15) as $rule) {
        expect($mild->introLines)->toContain($rule);
    }

    // Ana ran the group, so her note thanks her for it; the runs-it line is
    // for the survivor's commissioner alone.
    expect(implode(' ', $mild->introLines))
        ->toContain(Voice::line('notify.groups_merged.ran', ['former' => 'Fourth and Long'], for: $pg))
        ->not->toContain(Voice::line('notify.groups_merged.runs_it', ['size' => '15', 'deadline' => 'Thu 12:00pm ET'], for: $pg));
});

it('leaves an inbox row in the reader\'s register once the queued note has run', function () {
    // Un-faked: the real channels, on the suite's sync queue.
    $f = mergeFixture();

    app(MergeGroups::class)->handle($f['survivor'], collect([$f['fourth']]), ContestMode::Woodshed, true);

    $row = $f['ben']->notifications()->sole();

    expect($row->data['kind'])->toBe('groups-merged')
        ->and($row->data['key'])->toBe('notify.groups_merged.inbox.moved')
        ->and($row->data['url'])->toBe(route('pickem.group', $f['survivor']));

    $f['ben']->update(['content_rating' => ContentRating::R]);

    Livewire::actingAs($f['ben']->fresh())->test('inbox')
        ->assertSee(Voice::line('notify.groups_merged.inbox.moved', $row->data['replace'], for: $f['ben']->fresh()));
});

it('lists every private group and its code when no survivor is named', function () {
    mergeFixture();

    $this->artisan('pickem:merge-groups')
        ->expectsOutputToContain('WDSQVFOX')
        ->expectsOutputToContain('FOURTHLG')
        ->assertFailed();
});

it('insists on both choices and on codes that exist', function () {
    mergeFixture();

    // No --ledger: a default on a command that deletes groups is a
    // decision nobody made.
    $this->artisan('pickem:merge-groups', ['into' => 'WDSQVFOX', '--from' => ['FOURTHLG'], '--mode' => 'woodshed', '--dry' => true])
        ->assertFailed();

    $this->artisan('pickem:merge-groups', ['into' => 'WDSQVFOX', '--from' => ['FOURTHLG,NOPE1234'], '--mode' => 'woodshed', '--ledger' => 'fresh', '--dry' => true])
        ->expectsOutputToContain('NOPE1234')
        ->assertFailed();

    expect(Group::query()->where('code', 'FOURTHLG')->exists())->toBeTrue();
});

it('prints the plan on a dry run and changes nothing', function () {
    Notification::fake();
    $f = mergeFixture();

    $this->artisan('pickem:merge-groups', [
        'into' => 'wdsqvfox', '--from' => ['FOURTHLG', 'picksixs'], '--mode' => 'woodshed', '--ledger' => 'fresh', '--dry' => true,
    ])
        ->expectsOutputToContain('Fourth and Long')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Group::query()->whereKey([$f['fourth']->id, $f['pickSix']->id])->count())->toBe(2)
        ->and(GroupMember::query()->where('group_id', $f['survivor']->id)->count())->toBe(2)
        ->and($f['contest']->fresh()->mode)->toBe(ContestMode::Classic)
        ->and($f['survivorWeek']->fresh()->exhibition)->toBeFalse();

    Notification::assertNothingSent();
});

it('refuses a real run in production without --force, and merges with it', function () {
    Notification::fake();
    $f = mergeFixture();
    $this->app['env'] = 'production';

    $args = ['into' => 'WDSQVFOX', '--from' => ['FOURTHLG,PICKSIXS'], '--mode' => 'woodshed', '--ledger' => 'fresh'];

    $this->artisan('pickem:merge-groups', $args)->assertFailed();

    expect(Group::query()->whereKey($f['fourth']->id)->exists())->toBeTrue();
    Notification::assertNothingSent();

    $this->artisan('pickem:merge-groups', [...$args, '--force' => true])
        ->expectsOutputToContain('Queued 6 notices')
        ->assertSuccessful();

    expect(Group::query()->whereKey($f['fourth']->id)->exists())->toBeFalse();
});

/** A note as Ana would get it, built by hand for the channel and copy tests. */
function mergedNote(): GroupsMerged
{
    return new GroupsMerged(
        variant: GroupsMerged::MOVED,
        groupId: 7,
        group: 'Goalpost Salvage Co.',
        url: 'https://campusfootball.test/groups/7',
        mode: ContestMode::Woodshed->value,
        size: 15,
        pivoted: true,
        freshStart: true,
        former: ['Fourth and Long'],
        ran: ['Fourth and Long'],
        runsIt: false,
        count: 4,
        deadline: 'Thu 12:00pm ET',
    );
}
