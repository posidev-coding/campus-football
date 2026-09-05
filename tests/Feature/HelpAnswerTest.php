<?php

use App\Actions\GrantWalletEntry;
use App\Ai\Agents\HelpQuestion;
use App\Enums\AiModel;
use App\Enums\ContentRating;
use App\Models\AiSpend;
use App\Models\PickemSetting;
use App\Models\User;
use App\Support\Cadence;
use App\Support\HelpAnswer;
use App\Support\HelpTopics;
use App\Support\Voice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/*
 * The model never emits a fact here either. It names WHICH topic and a person
 * wrote the answer — so every test below asks the same two questions the stat
 * answers ask: did the gate run before the call, and did our own copy get the
 * last word.
 *
 * Declining is the cheap outcome by design: a miss hands the question to the
 * feedback form, so "we do not know" costs the reader one tap.
 */

beforeEach(function () {
    config()->set('cfb.ai_enabled', true);
    config()->set('cfb.ai_help', true);

    $this->reader = User::factory()->create();
});

/** A well-formed intent, overridable per assertion. */
function helpIntent(array $overrides = []): array
{
    return [
        'answerable' => true,
        'topic' => 'picks.lock',
        'note' => '',
        ...$overrides,
    ];
}

function askHelp(string $question = 'When do my picks lock?', ?User $user = null): array
{
    return app(HelpAnswer::class)->for($question, $user ?? test()->reader);
}

/**
 * Nobody signed in, which `askHelp()` cannot express: its `?? test()->reader`
 * reads a null user as "you did not pass one" and hands over the signed-in
 * reader, so a nullable parameter can mean the default or the guest but never
 * both. The guest is the cheapest of the spend gates and needs its own door.
 */
function askHelpAsGuest(string $question = 'When do my picks lock?'): array
{
    return app(HelpAnswer::class)->for($question, null);
}

describe('the calls it never makes', function () {
    it('never prompts for a guest', function () {
        // Both halves or neither: a guest has to come back with no answer AND
        // no call behind it. Reading is never gated, but an answer is a bill
        // an anonymous session cannot be capped against.
        HelpQuestion::fake([helpIntent()]);

        expect(HelpAnswer::available(null))->toBeFalse()
            ->and(askHelpAsGuest()[0])->toBeNull();

        HelpQuestion::assertNeverPrompted();
    });

    it('never prompts while the help flag is closed', function () {
        config()->set('cfb.ai_help', false);
        HelpQuestion::fake([helpIntent()]);

        expect(askHelp()[0])->toBeNull();
        HelpQuestion::assertNeverPrompted();
    });

    it('never prompts while the master switch is off', function () {
        config()->set('cfb.ai_enabled', false);
        HelpQuestion::fake([helpIntent()]);

        expect(askHelp()[0])->toBeNull();
        HelpQuestion::assertNeverPrompted();
    });

    it('never prompts for something that is not a question', function () {
        HelpQuestion::fake([helpIntent()]);

        expect(askHelp('join group')[0])->toBeNull();
        HelpQuestion::assertNeverPrompted();
    });

    it('never prompts once the month is spent', function () {
        config()->set('cfb.ai_monthly_budget', 1);
        AiSpend::create([
            'model' => AiModel::Haiku45->value,
            'feature' => 'help',
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'cost' => 1.5,
        ]);
        HelpQuestion::fake([helpIntent()]);

        expect(askHelp()[0])->toBeNull();
        HelpQuestion::assertNeverPrompted();
    });

    it('never prompts past the reader\'s daily cap, and says so', function () {
        for ($i = 0; $i < HelpAnswer::DAILY_CAP; $i++) {
            RateLimiter::hit('ai-help:'.$this->reader->id, 86400);
        }

        HelpQuestion::fake([helpIntent()]);

        expect(HelpAnswer::capped($this->reader))->toBeTrue()
            ->and(askHelp()[0])->toBeNull();

        HelpQuestion::assertNeverPrompted();
    });
});

describe('what it answers', function () {
    it('resolves a topic to its title, its body and a place to go', function () {
        HelpQuestion::fake([helpIntent()]);

        [$answer, $reason] = askHelp();

        expect($reason)->toBe('resolved')
            ->and($answer['topic'])->toBe('picks.lock')
            ->and($answer['title'])->toBe('When picks lock')
            ->and($answer['body'])->toBe(Voice::line('help.picks.lock', for: $this->reader))
            ->and($answer['href'])->toBe(route('pickem.home'))
            ->and($answer['cta'])->toBe('Take me there');
    });

    it('fills a live number from the code the screen reads', function () {
        HelpQuestion::fake([helpIntent(['topic' => 'wallet.tallboys'])]);

        expect(askHelp('How do I get more Tallboys?')[0]['body'])
            ->toContain('holds '.GrantWalletEntry::COOLER_CAPACITY)
            ->toContain(GrantWalletEntry::PICKEM_WIN_XP.' XP')
            ->not->toMatch('/:[a-z_]+/');
    });

    it('reads the commissioner\'s deadline off the league clock, not a weekday somebody typed', function () {
        /*
         * The wrong-default class: a hardcoded "Tuesday" passes every other
         * test, so the setting is moved OFF its default and the body has to
         * follow it. The laws partial once shipped exactly this drift.
         */
        PickemSetting::current()->forceFill(['slate_deadline_dow' => 2, 'slate_deadline_time' => '20:00:00'])->save();
        Cadence::flush();

        HelpQuestion::fake([helpIntent(['topic' => 'groups.slate'])]);

        expect(askHelp('When is the slate due?')[0]['body'])
            ->toContain(Cadence::deadlineLabel())
            ->not->toContain('Thu');
    });

    it('speaks in the reader\'s register without anybody signed in', function () {
        // No actingAs on purpose: Voice::line() falls back to auth()->user()
        // when `for:` is forgotten, and in a queue that is nobody — so the
        // answer has to carry the reader itself.
        $pg = User::factory()->make(['content_rating' => ContentRating::Pg]);
        $r = User::factory()->make(['content_rating' => ContentRating::R]);

        expect(HelpTopics::answer('picks.privacy', $pg)['body'])->toBe(Voice::line('help.picks.privacy', for: $pg))
            ->and(HelpTopics::answer('picks.privacy', $r)['body'])->not->toBe(HelpTopics::answer('picks.privacy', $pg)['body']);
    });

    it('sends an unverified reader to verify, and a verified one to Account', function () {
        $unverified = User::factory()->make(['email_verified_at' => null]);
        $verified = User::factory()->make(['email_verified_at' => now()]);

        expect(HelpTopics::answer('wallet.verify', $unverified)['href'])->toBe(route('verification.notice'))
            ->and(HelpTopics::answer('wallet.verify', $verified)['href'])->toBe(route('account'));
    });

    it('starts the tour from its own door', function () {
        $answer = HelpTopics::answer('account.tours', $this->reader);

        expect($answer['href'])->toBe(route('home', ['tour' => 1]))
            ->and($answer['cta'])->toBe('Start the tour');
    });
});

describe('what it refuses', function () {
    it('takes unanswerable for an answer, and keeps the reason', function () {
        HelpQuestion::fake([helpIntent(['answerable' => false, 'topic' => '', 'note' => 'That is a score, not a how-to.'])]);

        [$answer, $reason] = askHelp('How many points did Tennessee score?');

        expect($answer)->toBeNull()
            ->and($reason)->toBe('That is a score, not a how-to.');
    });

    it('refuses a topic outside the vocabulary rather than guessing', function () {
        HelpQuestion::fake([helpIntent(['topic' => 'picks.nope'])]);

        expect(askHelp()[0])->toBeNull()
            ->and(HelpTopics::answer('picks.nope', $this->reader))->toBeNull();
    });

    it('never caches a failure, so the next ask tries again', function () {
        HelpQuestion::fake(fn () => throw new RuntimeException('gateway exploded'));

        expect(askHelp()[0])->toBeNull();

        HelpQuestion::fake([helpIntent()]);

        expect(askHelp()[0])->not->toBeNull();

        // TWO, and the second prompt IS the assertion: the same question asked
        // again had to reach the model, which it can only do if the failure
        // was never written to the cache. One would mean a blip pinned "we
        // cannot answer this" for a day. The fake's counter carries across the
        // re-fake above, so this is the total of both asks.
        HelpQuestion::assertPromptedTimes(2);
    });
});

describe('what it costs', function () {
    it('charges one Haiku call', function () {
        HelpQuestion::fake([helpIntent()]);

        askHelp();

        // later() defers past the response; assert on the call, not the row.
        HelpQuestion::assertPromptedTimes(1);
    });

    it('collapses a re-ask to zero calls', function () {
        HelpQuestion::fake([helpIntent()]);

        askHelp();
        askHelp();

        HelpQuestion::assertPromptedTimes(1);
    });

    it('collapses case and punctuation onto the same question', function () {
        HelpQuestion::fake([helpIntent()]);

        askHelp('When do my picks lock?');
        askHelp('when do  my picks lock');

        HelpQuestion::assertPromptedTimes(1);
    });

    it('spends a question from the cap only when it actually calls', function () {
        HelpQuestion::fake([helpIntent()]);

        askHelp();
        askHelp();

        expect(RateLimiter::attempts('ai-help:'.$this->reader->id))->toBe(1);
    });
});

describe('the screen', function () {
    it('opens on Ask when the flag is open, and never renders it when closed', function () {
        Livewire::actingAs($this->reader)->test('help-sheet')
            ->assertSet('tab', 'ask')
            ->assertSee('What are you stuck on?')
            ->assertSee('When do my picks lock?');

        config()->set('cfb.ai_help', false);

        Livewire::actingAs($this->reader)->test('help-sheet')
            ->assertSet('tab', 'feedback')
            ->assertDontSee('What are you stuck on?')
            ->assertDontSee('When do my picks lock?')
            // A tampered tab lands back on the pane that exists.
            ->set('tab', 'ask')
            ->assertSet('tab', 'feedback');
    });

    it('renders without a single query', function () {
        // The sheet is on every signed-in page. The flag, the examples and
        // the copy are all constants or the loaded user; this pins it.
        DB::enableQueryLog();

        Livewire::actingAs($this->reader)->test('help-sheet')->assertSet('tab', 'ask');

        expect(DB::getQueryLog())->toBe([]);
    });

    it('answers a tapped example without a model, and ignores an index nobody offered', function () {
        HelpQuestion::fake([helpIntent()])->preventStrayPrompts();

        Livewire::actingAs($this->reader)->test('help-sheet')
            ->call('askExample', 0)
            ->assertSet('askState', 'answered')
            ->assertSet('q', 'When do my picks lock?')
            ->assertSee('When picks lock')
            ->assertSee(route('pickem.home'))
            ->call('askExample', 99)
            ->assertSet('askState', 'answered');

        HelpQuestion::assertNeverPrompted();
    });

    it('answers a typed question, and prints the question over the answer', function () {
        HelpQuestion::fake([helpIntent(['topic' => 'lobby.cost'])]);

        Livewire::actingAs($this->reader)->test('help-sheet')
            ->set('q', 'What does a room cost?')
            ->call('ask')
            ->assertHasNoErrors()
            ->assertSet('askState', 'answered')
            ->assertSee('What does a room cost?')
            ->assertSee('What a room costs')
            ->assertSee('Take me there')
            ->assertSee(route('pickem.how'));
    });

    it('hands a miss to the feedback pane with the question in the box', function () {
        HelpQuestion::fake([helpIntent(['answerable' => false, 'note' => 'Not a how-to.'])]);

        Livewire::actingAs($this->reader)->test('help-sheet')
            ->set('q', 'Why is my cousin in my group?')
            ->call('ask')
            ->assertSet('askState', 'missed')
            ->assertSee(Voice::line('help.none', for: $this->reader))
            ->assertSee('Send this as feedback')
            ->call('sendAsFeedback')
            ->assertSet('tab', 'feedback')
            ->assertSet('kind', 'confused')
            ->assertSet('body', 'Why is my cousin in my group?')
            ->assertSee('What happened, or what would help?');
    });

    it('refuses a half-typed entry with a plain instruction, and never prompts', function () {
        HelpQuestion::fake([helpIntent()])->preventStrayPrompts();

        Livewire::actingAs($this->reader)->test('help-sheet')
            ->set('q', 'picks')
            ->call('ask')
            ->assertHasErrors(['q'])
            ->assertSee('Ask a whole question.')
            ->assertSet('askState', 'idle');

        HelpQuestion::assertNeverPrompted();
    });

    it('says so on screen at the cap', function () {
        for ($i = 0; $i < HelpAnswer::DAILY_CAP; $i++) {
            RateLimiter::hit('ai-help:'.$this->reader->id, 86400);
        }

        HelpQuestion::fake([helpIntent()])->preventStrayPrompts();

        Livewire::actingAs($this->reader)->test('help-sheet')
            ->set('q', 'When do my picks lock?')
            ->call('ask')
            ->assertSet('askState', 'capped')
            ->assertSee(Voice::line('help.capped', ['cap' => HelpAnswer::DAILY_CAP], $this->reader));

        HelpQuestion::assertNeverPrompted();
    });
});
