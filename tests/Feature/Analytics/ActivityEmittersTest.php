<?php

use App\Actions\RecordActivity;
use App\Enums\ActivityKind;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;

/*
 * The five moments with no truth table, and the one file each of them is
 * allowed to be emitted from.
 *
 * The sweep below is `UxFunnelTest`'s, generalized to a second vocabulary,
 * and it exists for the bug that test was written for: `onboarding_registered`
 * was emitted from the wizard alone, so it counted wizard completions rather
 * than registrations and agreed dead-on with the two steps after it — a number
 * that was wrong and looked right. A kind emitted from two screens stops
 * measuring what it is named after, and nothing else in a suite notices.
 */

beforeEach(function () {
    Redis::connection('pulse')->flushdb();
});

/** Every kind on the stream, in order. */
function recordedKinds(): array
{
    return array_values(array_map(
        fn ($fields) => ((array) $fields)['kind'],
        (array) Redis::connection('pulse')->xRange(RecordActivity::STREAM, '-', '+'),
    ));
}

/** Every facet on the stream, in order. */
function recordedFacets(): array
{
    return array_values(array_map(
        fn ($fields) => ((array) $fields)['facet'],
        (array) Redis::connection('pulse')->xRange(RecordActivity::STREAM, '-', '+'),
    ));
}

/**
 * Every file under app/ and resources/views/ that WRITES this kind.
 *
 * The match is the emitting call — `action()` or the internal `push()` — and
 * not the bare name, because a READER is not an emitter and phase 3 added
 * two of them: `ActivityRollup` asks whether a row is a page view or a search
 * to set the feature bits, and `OpsReport` counts yesterday's page views to
 * see whether the rollup ran. Neither can make a number disagree with itself,
 * which is the bug this sweep exists for. The kind may appear on the line
 * after the call — the account screen and the help sheet both wrap — so the
 * pattern crosses whitespace.
 */
function emittersOf(ActivityKind $kind): array
{
    $pattern = '/(?:->action|push)\(\s*ActivityKind::'.$kind->name.'\b/';

    return collect(File::allFiles(base_path('app')))
        ->merge(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => preg_match($pattern, $file->getContents()) === 1)
        ->map(fn ($file) => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->sort()
        ->values()
        ->all();
}

/** Every file under app/ and resources/views/ containing this needle. */
function callersOf(string $needle): array
{
    return collect(File::allFiles(base_path('app')))
        ->merge(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_contains($file->getContents(), $needle))
        ->map(fn ($file) => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->sort()
        ->values()
        ->all();
}

describe('one emitter per kind', function () {
    it('records a page view from the middleware and from nowhere else', function () {
        // The kind is WRITTEN in exactly one place…
        expect(emittersOf(ActivityKind::PageView))->toBe(['app/Actions/RecordActivity.php']);

        // …and the one door to it is the middleware. A Livewire mount hook
        // reaching for this would count three or four per screen, because a
        // navigate hop re-mounts every layout island.
        expect(callersOf('->pageView('))->toBe(['app/Http/Middleware/RecordPageView.php']);
    });

    it('records a search from one shared concern, not from three surfaces', function () {
        /*
         * Home's panel, /search and the ⌘K palette all count searches, and
         * three copies of the "has this one already counted?" guard is three
         * chances for one of them to drift into counting keystrokes.
         */
        expect(emittersOf(ActivityKind::Searched))
            ->toBe(['app/Livewire/Concerns/RecordsSearches.php']);
    });

    it('records a stat question from the shared ask, and a help question from the sheet', function () {
        expect(emittersOf(ActivityKind::StatAsked))
            ->toBe(['app/Livewire/Concerns/AsksQuestions.php']);

        expect(emittersOf(ActivityKind::HelpAsked))
            ->toBe(['resources/views/livewire/help-sheet.blade.php']);
    });

    it('records a notification toggle from the device endpoint and the list switches', function () {
        /*
         * Two files, deliberately, and they measure different things: a push
         * subscription belongs to a DEVICE and is written by the browser's
         * own round trip, while the newsletter, pick'em and SMS switches are
         * columns on the account. Neither can write the other's row, so
         * neither can be folded into the other.
         */
        expect(emittersOf(ActivityKind::NotificationToggled))->toBe([
            'app/Http/Controllers/PushSubscriptionController.php',
            'resources/views/livewire/account.blade.php',
        ]);
    });

    it('records a share from nowhere, because no share reaches the server yet', function () {
        /*
         * Not an omission — a decision the enum already wrote down. Every way
         * to pass an invite on today (copy the link, copy a template, read the
         * QR, the OS share sheet) is pure Alpine and never leaves the browser,
         * and observing one would mean adding a network round trip to a local
         * action: the reader pays for the measurement. The case stays in the
         * vocabulary so the day a share DOES reach the server it has a name,
         * and this pins that nobody quietly reached for a beacon meanwhile.
         *
         * Sending an invite to a named person is not this: it is a
         * `group_invites` row, and `ActivityFeature::Invited` reads it at
         * rollup.
         */
        expect(emittersOf(ActivityKind::Shared))->toBe([]);
    });
});

describe('what the emitters actually count', function () {
    it('counts one search however long the query gets', function () {
        Livewire::test('search-page')
            ->set('q', 'a')          // too short — the app is not looking yet
            ->set('q', 'ten')
            ->set('q', 'tenne')
            ->set('q', 'tennessee');

        expect(recordedKinds())->toBe(['searched']);
    });

    it('counts a search on every surface that has a box', function () {
        foreach (['search-panel', 'search-page', 'search'] as $surface) {
            Redis::connection('pulse')->flushdb();

            Livewire::test($surface)->set('q', 'tennessee');

            expect(recordedKinds())->toBe(['searched'], "surface: {$surface}");
        }
    });

    it('counts a help question with whether it landed', function () {
        config(['cfb.ai_enabled' => true, 'cfb.ai_help' => true]);

        // The tapped example, which resolves out of HelpTopics rather than a
        // model — the same door through the counter, and no bill.
        Livewire::actingAs(User::factory()->create())
            ->test('help-sheet')
            ->call('askExample', 0);

        expect(recordedKinds())->toBe(['help_asked'])
            ->and(recordedFacets())->toBe(['answered']);
    });

    it('counts nothing for a question the help sheet refuses to take', function () {
        config(['cfb.ai_enabled' => true, 'cfb.ai_help' => true]);

        // An empty box is an instruction, not a question — and a total that
        // counted it would be counting the validation message.
        Livewire::actingAs(User::factory()->create())
            ->test('help-sheet')
            ->set('q', '')
            ->call('ask')
            ->assertHasErrors('q');

        expect(recordedKinds())->toBe([]);
    });

    it('counts a list switch with which list and which way', function () {
        Livewire::actingAs(User::factory()->create())
            ->test('account')
            ->set('newsletter_opt_in', false)
            ->set('pickem_notify_opt_in', false)
            ->set('newsletter_opt_in', true);

        expect(recordedKinds())->toBe(array_fill(0, 3, 'notification_toggled'))
            ->and(recordedFacets())->toBe(['newsletter_off', 'pickem_off', 'newsletter_on']);
    });

    it('counts a push subscription once, and its removal', function () {
        Notification::fake();

        $user = User::factory()->create();

        $payload = ['endpoint' => 'https://push.example/abc', 'keys' => ['p256dh' => 'k', 'auth' => 'a']];

        $this->actingAs($user)->postJson(route('push.store'), $payload)->assertNoContent();

        // The same device re-registering is a hop, a key rotation or a second
        // tab — not somebody turning notifications on again.
        $this->actingAs($user)->postJson(route('push.store'), $payload)->assertNoContent();

        $this->actingAs($user)->deleteJson(route('push.destroy'), ['endpoint' => $payload['endpoint']])->assertNoContent();

        expect(recordedFacets())->toBe(['push_on', 'push_off']);
    });

    it('never records a subject on a page view', function () {
        [$member, $group] = pickemContest();

        $this->actingAs($member)->get(route('pickem.group', $group))->assertOk();

        $entry = (array) collect(Redis::connection('pulse')->xRange(RecordActivity::STREAM, '-', '+'))->first();

        // A page view's route and facet are the whole of what it knows; a
        // subject id there would put a group id on every screen read.
        expect($entry['subject_type'])->toBe('')
            ->and($entry['subject_id'])->toBe('');
    });

    it('names a subject by its morph alias, never by a class name', function () {
        $group = Group::factory()->create();

        $this->actingAs(User::factory()->create());

        app(RecordActivity::class)->action(ActivityKind::Shared, request(), 'link', $group);

        $entry = (array) collect(Redis::connection('pulse')->xRange(RecordActivity::STREAM, '-', '+'))->first();

        // The alias, so a namespace move never strands a stored row — and it
        // fits the 16-char column, which a class name would not.
        expect($entry['subject_type'])->toBe('group')
            ->and($entry['subject_id'])->toBe((string) $group->id);
    });
});
