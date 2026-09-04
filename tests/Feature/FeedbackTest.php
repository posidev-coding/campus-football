<?php

use App\Actions\SendFeedback;
use App\Enums\ContentRating;
use App\Enums\FeedbackKind;
use App\Exceptions\FeedbackTooFast;
use App\Models\Feedback;
use App\Models\User;
use App\Support\Release;
use App\Support\Voice;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/*
 * FEEDBACK — one sheet, mounted once, and every gate in the Action.
 *
 * Two disciplines carry these tests. The first: the sheet is on every
 * signed-in page, so what it must NOT do (query, render for a guest, take
 * the browser's word for the page) matters as much as what it does. The
 * second: the note is a person's own words, so the body is REJECTED past the
 * width rather than truncated, while the context around it — page, viewport,
 * user agent — is reduced and clamped and never a reason to refuse.
 */

/** A well-formed context, overridable per assertion. */
function feedbackContext(array $overrides = []): array
{
    return [
        'path' => '/picks',
        'viewport' => 390,
        'standalone' => true,
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
        ...$overrides,
    ];
}

describe('the action', function () {
    it('writes a note with its kind, its page and its author, trimmed', function () {
        $user = User::factory()->create();

        $note = app(SendFeedback::class)->handle($user, FeedbackKind::Bug, '  The lock button did nothing.  ', feedbackContext());

        expect($note->kind)->toBe(FeedbackKind::Bug)
            ->and($note->body)->toBe('The lock button did nothing.')
            ->and($note->user_id)->toBe($user->id)
            ->and($note->path)->toBe('/picks')
            ->and($note->viewport)->toBe(390)
            ->and($note->standalone)->toBeTrue()
            ->and($note->user_agent)->toStartWith('Mozilla/5.0')
            ->and($note->handled_at)->toBeNull()
            ->and($note->workbook_item_id)->toBeNull();
    });

    it('stamps the release when there is one, and leaves it null when there is not', function () {
        $user = User::factory()->create();

        $stamp = tempnam(sys_get_temp_dir(), 'cfb-version');
        file_put_contents($stamp, "4.0.0-beta.11\n");
        config()->set('cfb.version_file', $stamp);
        Release::flush();

        expect(app(SendFeedback::class)->handle($user, FeedbackKind::Idea, 'Stamped.', feedbackContext())->release)
            ->toBe('v4.0.0-beta.11');

        // No stamp, no release: Release never invents one, and neither does
        // the row — null means "no data", not "unknown build".
        config()->set('cfb.version_file', $stamp.'.missing');
        Release::flush();

        expect(app(SendFeedback::class)->handle($user, FeedbackKind::Idea, 'Unstamped.', feedbackContext())->release)
            ->toBeNull();

        unlink($stamp);
    });

    it('keeps the path and drops everything that is not one, cut to the column', function () {
        $user = User::factory()->create();

        // A query string is where an invite code or a signed link rides.
        expect(app(SendFeedback::class)->handle($user, FeedbackKind::Bug, 'Query.', feedbackContext([
            'path' => '/join/ABC123?ref=text#top',
        ]))->path)->toBe('/join/ABC123');

        expect(app(SendFeedback::class)->handle($user, FeedbackKind::Bug, 'Long.', feedbackContext([
            'path' => str_repeat('/a', 3000),
        ]))->path)->toHaveLength(255);

        expect(app(SendFeedback::class)->handle($user, FeedbackKind::Bug, 'Nothing.', feedbackContext([
            'path' => '   ',
        ]))->path)->toBeNull();
    });

    it('clamps a viewport the browser made up rather than refusing the note', function () {
        $user = User::factory()->create();

        expect(app(SendFeedback::class)->handle($user, FeedbackKind::Bug, 'Wide.', feedbackContext(['viewport' => 999_999]))->viewport)
            ->toBe(65535)
            ->and(app(SendFeedback::class)->handle($user, FeedbackKind::Bug, 'Words.', feedbackContext(['viewport' => 'wide']))->viewport)
            ->toBeNull()
            ->and(app(SendFeedback::class)->handle($user, FeedbackKind::Bug, 'Negative.', feedbackContext(['viewport' => -5]))->viewport)
            ->toBe(0)
            ->and(app(SendFeedback::class)->handle($user, FeedbackKind::Bug, 'Flag.', feedbackContext(['standalone' => 'false']))->standalone)
            ->toBeFalse();
    });

    it('refuses an empty note and one past the width, and writes nothing', function () {
        $user = User::factory()->create();

        expect(fn () => app(SendFeedback::class)->handle($user, FeedbackKind::Idea, "   \n  ", feedbackContext()))
            ->toThrow(InvalidArgumentException::class);

        // The thousand-and-first character is a refusal the writer can see,
        // never a silent edit of what they said.
        expect(fn () => app(SendFeedback::class)->handle($user, FeedbackKind::Idea, str_repeat('x', SendFeedback::MAX_LENGTH + 1), feedbackContext()))
            ->toThrow(InvalidArgumentException::class);

        expect(Feedback::query()->count())->toBe(0);

        expect(app(SendFeedback::class)->handle($user, FeedbackKind::Idea, str_repeat('x', SendFeedback::MAX_LENGTH), feedbackContext())->body)
            ->toHaveLength(SendFeedback::MAX_LENGTH);
    });

    it('throttles the sixth note in an hour, and a refused note does not spend the hour', function () {
        $user = User::factory()->create();

        // An empty tap first: refused before the limiter, so it costs nothing.
        expect(fn () => app(SendFeedback::class)->handle($user, FeedbackKind::Idea, '', feedbackContext()))
            ->toThrow(InvalidArgumentException::class);

        foreach (range(1, SendFeedback::MAX_PER_WINDOW) as $i) {
            app(SendFeedback::class)->handle($user, FeedbackKind::Idea, "Note {$i}.", feedbackContext());
        }

        expect(fn () => app(SendFeedback::class)->handle($user, FeedbackKind::Idea, 'One too many.', feedbackContext()))
            ->toThrow(FeedbackTooFast::class);

        expect(Feedback::query()->count())->toBe(SendFeedback::MAX_PER_WINDOW);

        // The window is the spelled-out constant, not a derivation that goes
        // negative in Carbon 3 and fails open.
        $this->travel(SendFeedback::WINDOW + 1)->seconds();

        expect(app(SendFeedback::class)->handle($user, FeedbackKind::Idea, 'An hour later.', feedbackContext()))
            ->toBeInstanceOf(Feedback::class);
    });

    it('spells the window out rather than deriving it', function () {
        expect(SendFeedback::WINDOW)->toBe(3600)
            ->and(SendFeedback::MAX_PER_WINDOW)->toBe(5)
            ->and(SendFeedback::MAX_LENGTH)->toBe(1000);
    });
});

describe('the sheet', function () {
    it('mounts once for a signed-in reader, on a screen with a door and one without', function () {
        $user = User::factory()->create();

        foreach ([route('account'), route('scoreboard')] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            // The marker, not the scripts: once any Livewire test has run,
            // every page in the process carries the asset injector.
            expect(substr_count($html, 'data-help-sheet'))->toBe(1, "on {$url}")
                ->and($html)->toContain('data-modal="help"');
        }
    });

    it('never mounts for a guest', function () {
        $this->get(route('scoreboard'))
            ->assertOk()
            ->assertDontSee('data-help-sheet', false);
    });

    it('records the page it was opened from, and the browser cannot rewrite it', function () {
        $user = User::factory()->create();

        // The path rides the snapshot, JSON-escaped, which is how a full page
        // render can be asked what the component read off the request.
        $this->actingAs($user)->get(route('account'))
            ->assertOk()
            ->assertSee('\/account', false);

        expect(fn () => Livewire::actingAs($user)->test('help-sheet')->set('path', '/somewhere-else'))
            ->toThrow(Exception::class);
    });

    it('sends a note through the sheet and says so in the reader\'s register', function () {
        $user = User::factory()->create(['content_rating' => ContentRating::R]);

        Livewire::actingAs($user)
            ->test('help-sheet')
            ->set('kind', 'bug')
            ->set('body', 'The lock button did nothing.')
            ->call('send', 390, true)
            ->assertHasNoErrors()
            ->assertSee(Voice::line('feedback.sent', for: $user))
            ->assertSet('body', '');

        $note = Feedback::query()->sole();

        expect($note->user_id)->toBe($user->id)
            ->and($note->kind)->toBe(FeedbackKind::Bug)
            ->and($note->viewport)->toBe(390)
            ->and($note->standalone)->toBeTrue();
    });

    it('refuses an empty note with a plain instruction, and writes nothing', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('help-sheet')
            ->set('body', '   ')
            ->call('send')
            ->assertHasErrors(['body'])
            ->assertSee('Write the note first.');

        expect(Feedback::query()->count())->toBe(0);
    });

    it('tells a reader who is too fast how long to wait, in minutes', function () {
        $user = User::factory()->create(['content_rating' => ContentRating::Pg]);

        foreach (range(1, SendFeedback::MAX_PER_WINDOW) as $i) {
            RateLimiter::hit("feedback:{$user->id}", SendFeedback::WINDOW);
        }

        Livewire::actingAs($user)
            ->test('help-sheet')
            ->set('body', 'The sixth.')
            ->call('send')
            ->assertHasErrors(['body'])
            ->assertSee(Voice::line('feedback.too_fast', ['wait' => '60 minutes'], $user));

        expect(Feedback::query()->count())->toBe(0);
    });

    it('answers a tampered kind by resetting it, and writes nothing', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('help-sheet')
            ->set('kind', 'rant')
            ->set('body', 'Anything.')
            ->call('send')
            ->assertSet('kind', 'idea');

        expect(Feedback::query()->count())->toBe(0);
    });

    it('survives a write that fails, and says whose fault it is', function () {
        $user = User::factory()->create();

        $this->mock(SendFeedback::class)
            ->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('the disk is full'));

        Livewire::actingAs($user)
            ->test('help-sheet')
            ->set('body', 'A note into the void.')
            ->call('send')
            ->assertSee(Voice::line('feedback.failed', for: $user))
            // The draft survives a failure: nothing was sent, so nothing is
            // cleared.
            ->assertSet('body', 'A note into the void.');
    });
});

describe('the doors', function () {
    it('renders the door on Account and in the desktop menu', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account'))
            ->assertOk()
            ->assertSee('Send feedback')
            ->assertSee("modal-show', { name: 'help' }", false);
    });

    it('renders the door at the foot of the rules page', function () {
        config()->set('cfb.pickem_open', true);
        pickemSeasonWeek();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('pickem.how'))
            ->assertOk()
            ->assertSee('Still stuck? Send feedback');
    });

    it('changes every door\'s word when the help flag is open, and only then', function () {
        config()->set('cfb.ai_enabled', true);
        config()->set('cfb.ai_help', true);
        config()->set('cfb.pickem_open', true);
        pickemSeasonWeek();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account'))
            ->assertOk()
            ->assertSee('Help & feedback')
            ->assertDontSee('Send feedback');

        $this->actingAs($user)->get(route('pickem.how'))
            ->assertOk()
            ->assertSee('Still stuck? Ask a question');

        expect(Livewire::actingAs($user)->test('pickem-home')->html())
            ->toContain('Help &amp; feedback')
            ->toContain('A question, a bug, or an idea.');
    });

    it('renders the door under the rules door at the foot of My Picks', function () {
        config()->set('cfb.pickem_open', true);
        pickemSeasonWeek();
        $user = User::factory()->create();

        $html = Livewire::actingAs($user)->test('pickem-home')->html();

        expect($html)->toContain('How this works')
            ->toContain('Send feedback')
            ->toContain('A bug, an idea, or something that made no sense.');

        // Under, not above: the rules door is the tour's spotlight and the
        // measured stack ends on it.
        expect(strpos($html, 'How this works'))->toBeLessThan(strpos($html, 'Send feedback'));
    });
});

describe('the voice', function () {
    it('speaks every register on the feedback family, carries the wait, and names no school', function () {
        $lines = (new ReflectionClass(Voice::class))->getConstant('LINES');

        foreach (['feedback.subheading', 'feedback.sent', 'feedback.too_fast', 'feedback.failed'] as $key) {
            expect($lines)->toHaveKey($key);
            expect($lines[$key])->toHaveKeys(['pg', 'pg13', 'r']);

            foreach ($lines[$key] as $line) {
                expect($line)->not->toContain('Georgia');
            }
        }

        foreach ($lines['feedback.too_fast'] as $line) {
            expect($line)->toContain(':wait');
        }
    });
});
