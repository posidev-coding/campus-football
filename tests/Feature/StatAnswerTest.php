<?php

use App\Ai\Agents\StatQuestion;
use App\Enums\AiModel;
use App\Models\AiSpend;
use App\Models\Athlete;
use App\Models\AthleteGameStat;
use App\Models\AthleteSeasonStat;
use App\Models\AthleteTeamSeason;
use App\Models\Game;
use App\Models\Season;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\TeamSeasonStat;
use App\Models\User;
use App\Services\CfbCalendar;
use App\Services\Stats\AggregateAthleteStats;
use App\Support\AskExamples;
use App\Support\StatAnswer;
use App\Support\Stats\StatCatalog;
use App\Support\Voice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/*
 * The model never emits a fact here. It names WHAT was asked for and the
 * database answers — so every test below is really asking the same question
 * twice: did the intent get through, and did our own data get the last word.
 *
 * Declining is the cheap outcome by design. The answer only ever runs where
 * search already found nothing, so a decline puts the reader exactly where
 * they were, while a confident wrong number does not.
 */

beforeEach(function () {
    config()->set('cfb.ai_enabled', true);
    config()->set('cfb.ai_answers', true);

    $this->season = Season::factory()->create(['year' => 2025, 'type' => Season::REGULAR]);

    $this->vols = Team::factory()->create([
        'display_name' => 'Tennessee Volunteers',
        'location' => 'Tennessee',
        'abbreviation' => 'TENN',
    ]);

    $this->cats = Team::factory()->create([
        'display_name' => 'Kentucky Wildcats',
        'location' => 'Kentucky',
        'abbreviation' => 'UK',
    ]);

    // resultsYear() is "the latest season with games PLAYED", so the fixture
    // has to have played one or every answer is "we hold nothing".
    $this->game = Game::factory()->create([
        'season_id' => $this->season->id,
        'home_team_id' => $this->cats->id,
        'away_team_id' => $this->vols->id,
        'completed' => true,
        'kickoff_at' => '2025-11-29 15:30:00',
        // Pinned rather than left to the factory: one test searches for this
        // exact string, and the row on screen renders a DERIVED short name.
        'name' => 'Tennessee Volunteers at Kentucky Wildcats',
    ]);

    $this->passer = Athlete::create([
        'id' => 4685578,
        'display_name' => 'Brandon Faizon',
        'last_name' => 'Faizon',
        'is_active' => true,
    ]);

    AthleteTeamSeason::create([
        'athlete_id' => $this->passer->id,
        'team_id' => $this->vols->id,
        'season_year' => 2025,
    ]);

    AthleteSeasonStat::create([
        'athlete_id' => $this->passer->id,
        'season_year' => 2025,
        'season_type' => AggregateAthleteStats::FULL_SEASON,
        'team_id' => $this->vols->id,
        'category' => 'passing',
        'stats' => ['passingYards' => 3412, 'passingTouchdowns' => 28],
    ]);

    AthleteGameStat::create([
        'athlete_id' => $this->passer->id,
        'game_id' => $this->game->id,
        'team_id' => $this->vols->id,
        'category' => 'passing',
        'stats' => ['passingYards' => 305],
    ]);

    TeamSeasonStat::create([
        'team_id' => $this->vols->id,
        'season_year' => 2025,
        'season_type' => Season::REGULAR,
        'category' => 'passing',
        'stats' => ['netPassingYards' => ['value' => 3500, 'display' => '3,500', 'rank' => 12]],
    ]);

    /*
     * A season that is SCHEDULED but has never been played, exactly as August
     * looks in real life. Without it `resultsYear()` and `currentYear()` return
     * the same number and a test for the wrong default passes for the wrong
     * reason — which is most of the time, per the house rule.
     */
    $this->upcoming = Season::factory()->create(['year' => 2026, 'type' => Season::REGULAR]);

    Game::factory()->create([
        'season_id' => $this->upcoming->id,
        'home_team_id' => $this->vols->id,
        'away_team_id' => $this->cats->id,
        'completed' => false,
        'kickoff_at' => '2026-09-05 15:30:00',
    ]);

    $this->reader = User::factory()->create();
});

function statIntent(array $overrides = []): array
{
    return [
        'answerable' => true,
        'subject' => 'player',
        'name' => 'Brandon Faizon',
        'metric' => 'passing.passingYards',
        'timeframe' => 'season',
        'season_year' => null,
        'note' => '',
        ...$overrides,
    ];
}

function askIt(string $question = 'How many passing yards did Brandon Faizon throw this season?', ?User $user = null): array
{
    return app(StatAnswer::class)->for($question, $user ?? test()->reader);
}

describe('the calls it never makes', function () {
    it('never prompts for a guest', function () {
        /*
         * Reading is never gated in this app — but an answer is a COMPUTATION
         * rather than a reading, and it has a bill an anonymous session cannot
         * be capped against. Guests get today's Search, unchanged.
         */
        StatQuestion::fake([statIntent()]);

        expect(StatAnswer::askable('How many passing yards did Brandon Faizon throw?', null))->toBeFalse()
            // Straight through the resolver, not the helper: a guest is the
            // one caller that must never fall back to somebody.
            ->and(app(StatAnswer::class)->for('How many passing yards did Brandon Faizon throw?', null)[0])
            ->toBeNull();

        StatQuestion::assertNeverPrompted();
    });

    it('never prompts while the answers flag is closed', function () {
        config()->set('cfb.ai_answers', false);
        StatQuestion::fake([statIntent()]);

        expect(askIt()[0])->toBeNull();
        StatQuestion::assertNeverPrompted();
    });

    it('never prompts while the master switch is off', function () {
        config()->set('cfb.ai_enabled', false);
        StatQuestion::fake([statIntent()]);

        expect(askIt()[0])->toBeNull();
        StatQuestion::assertNeverPrompted();
    });

    it('never prompts for something that is not a question', function () {
        // The one gate that runs on every keystroke, so it stays string work.
        StatQuestion::fake([statIntent()]);

        expect(askIt('Tennessee')[0])->toBeNull();
        StatQuestion::assertNeverPrompted();
    });

    it('never prompts once the month is spent', function () {
        config()->set('cfb.ai_monthly_budget', 1);
        AiSpend::create([
            'model' => AiModel::Haiku45->value,
            'feature' => 'answer',
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'cost' => 1.5,
        ]);
        StatQuestion::fake([statIntent()]);

        expect(askIt()[0])->toBeNull();
        StatQuestion::assertNeverPrompted();
    });

    it('never prompts past the reader\'s daily cap, and says so', function () {
        for ($i = 0; $i < StatAnswer::DAILY_CAP; $i++) {
            RateLimiter::hit('ai-answer:'.$this->reader->id, 86400);
        }

        StatQuestion::fake([statIntent()]);

        expect(StatAnswer::capped($this->reader))->toBeTrue()
            ->and(askIt()[0])->toBeNull();

        StatQuestion::assertNeverPrompted();
    });
});

describe('what reads like a question', function () {
    it('takes a question mark, an interrogative or five words', function () {
        expect(StatAnswer::looksLikeAQuestion('Gunner Stockton stats?'))->toBeTrue()
            ->and(StatAnswer::looksLikeAQuestion('how many passing yards'))->toBeTrue()
            ->and(StatAnswer::looksLikeAQuestion('passing yards for the vols please'))->toBeTrue();
    });

    it('leaves an ordinary search alone', function () {
        // Everything today's Search is actually used for has to pass straight
        // through — a name is not a question and must never cost anything.
        expect(StatAnswer::looksLikeAQuestion('Tennessee'))->toBeFalse()
            ->and(StatAnswer::looksLikeAQuestion('Brandon Faizon'))->toBeFalse()
            ->and(StatAnswer::looksLikeAQuestion('LSU'))->toBeFalse();
    });
});

describe('what it answers', function () {
    it('reads a season total out of our own table', function () {
        StatQuestion::fake([statIntent()]);

        [$answer] = askIt();

        expect($answer['kind'])->toBe('value')
            ->and($answer['value'])->toBe('3,412')
            ->and($answer['name'])->toBe('Brandon Faizon')
            // resultsYear(), never currentYear(): in August they differ and
            // the current one has no games in it at all.
            ->and($answer['context'])->toBe('2025 season');
    });

    it('names the game when the question is about last week', function () {
        /*
         * Deliberately the player's most recent COMPLETED game rather than a
         * resolved week — a week is a thing this app answers three ways, and a
         * reader asking about "last week" during a bye means the last time he
         * played. Naming the game makes any mismatch visible.
         */
        StatQuestion::fake([statIntent(['timeframe' => 'last_game'])]);

        [$answer] = askIt('How many passing yards did Brandon Faizon throw last week?');

        expect($answer['value'])->toBe('305')
            ->and($answer['context'])->toContain('at Kentucky')
            ->and($answer['context'])->toContain('Nov 29');
    });

    it('reads a team season total and carries ESPN\'s national rank', function () {
        StatQuestion::fake([statIntent([
            'subject' => 'team',
            'name' => 'Tennessee',
            'metric' => 'passing.netPassingYards',
        ])]);

        [$answer] = askIt('How many passing yards do Tennessee have this season?');

        expect($answer['value'])->toBe('3,500')
            ->and($answer['name'])->toBe('Tennessee Volunteers')
            ->and($answer['context'])->toContain('12th nationally');
    });

    it('ranks a leaderboard from the same query the stats screen uses', function () {
        TeamSeason::create([
            'team_id' => $this->vols->id,
            'season_year' => 2025,
            'classification' => 'FBS',
        ]);

        StatQuestion::fake([statIntent(['subject' => 'leaders', 'name' => ''])]);

        [$answer] = askIt('Who leads the country in passing yards this season?');

        expect($answer['kind'])->toBe('leaders')
            ->and($answer['rows'][0]['name'])->toBe('Brandon Faizon')
            ->and($answer['rows'][0]['team'])->toBe('TENN')
            ->and($answer['rows'][0]['value'])->toBe('3,412');
    });
});

describe('what it refuses', function () {
    it('takes unanswerable for an answer, and keeps the reason', function () {
        StatQuestion::fake([statIntent([
            'answerable' => false,
            'note' => 'Game quality is three different numbers in this app.',
        ])]);

        [$answer, $reason] = askIt('What is Tennessee\'s average game quality this season?');

        expect($answer)->toBeNull()->and($reason)->toContain('three different numbers');
    });

    it('refuses a team metric aimed at a player, which is the interceptions trap', function () {
        /*
         * The pair travels together everywhere: `interceptions` is picks CAUGHT
         * in one category and thrown in another, and player and team share stat
         * names outright. A vocabulary keyed on the word alone would answer
         * from whichever row came back first.
         */
        StatQuestion::fake([statIntent(['metric' => 'passing.netPassingYards'])]);

        [$answer, $reason] = askIt();

        expect($answer)->toBeNull()->and($reason)->toContain('not a metric we can look up for a player');
    });

    it('keeps thrown interceptions out of the vocabulary entirely', function () {
        // There is no `passing.interceptions` board, so there is nothing for a
        // question about picks THROWN to resolve to — and the caught ones live
        // under their own category where they cannot be confused for it.
        expect(StatCatalog::answerable())->not->toHaveKey('passing.interceptions')
            ->and(StatCatalog::answerable())->toHaveKey('interceptions.interceptions');
    });

    it('refuses an ambiguous name rather than taking the top row', function () {
        // "the top result" is how a confident answer about the wrong Smith
        // reaches somebody who has no way to tell.
        Athlete::create(['id' => 900001, 'display_name' => 'Marcus Tester', 'last_name' => 'Tester', 'is_active' => true]);
        Athlete::create(['id' => 900002, 'display_name' => 'Marcus Testerson', 'last_name' => 'Testerson', 'is_active' => true]);

        StatQuestion::fake([statIntent(['name' => 'Marcus'])]);

        [$answer, $reason] = askIt('How many passing yards did Marcus throw this season?');

        expect($answer)->toBeNull()->and($reason)->toContain('No single player');
    });

    it('says we hold nothing rather than printing a zero', function () {
        // Never write a default when data is missing. A zero here is a claim
        // that he threw for nothing, which is a different sentence.
        StatQuestion::fake([statIntent(['metric' => 'rushing.rushingYards'])]);

        [$answer, $reason] = askIt('How many rushing yards did Brandon Faizon get this season?');

        expect($answer)->toBeNull()->and($reason)->toContain('We hold no');
    });

    it('answers from the season with games PLAYED, not the one being scheduled', function () {
        /*
         * The August trap. `currentYear()` is 2026 here and holds no stats at
         * all, so reaching for it would make every answer "we hold nothing" —
         * which reads as the feature being broken rather than as the offseason.
         */
        $calendar = app(CfbCalendar::class);

        expect($calendar->currentYear())->toBe(2026)
            ->and($calendar->resultsYear())->toBe(2025);

        StatQuestion::fake([statIntent()]);

        expect(askIt()[0]['context'])->toBe('2025 season');
    });

    it('ignores a season we do not hold and says which one it used', function () {
        StatQuestion::fake([statIntent(['season_year' => 1998])]);

        [$answer] = askIt('How many passing yards did Brandon Faizon throw in 1998?');

        expect($answer['context'])->toBe('2025 season');
    });
});

describe('what it costs', function () {
    it('charges one Haiku call and records it against the answer feature', function () {
        StatQuestion::fake([statIntent()]);

        askIt();

        // later() defers past the response; there is none in a test, so the
        // deferred callbacks are flushed by the framework at teardown —
        // assert on the call having been made rather than on the row.
        StatQuestion::assertPromptedTimes(1);
    });

    it('collapses a re-ask to zero calls', function () {
        /*
         * The INTENT is cached, not the answer: a question means the same
         * thing tomorrow while the number behind it moves every Saturday. A
         * pilot all asking about the same game is one API call.
         */
        StatQuestion::fake([statIntent()]);

        askIt();
        askIt();

        StatQuestion::assertPromptedTimes(1);
    });

    it('collapses case and punctuation onto the same question', function () {
        StatQuestion::fake([statIntent()]);

        askIt('How many passing yards did Brandon Faizon throw this season?');
        askIt('how many  passing yards did brandon faizon throw this season');

        StatQuestion::assertPromptedTimes(1);
    });

    it('spends a question from the cap only when it actually calls', function () {
        StatQuestion::fake([statIntent()]);

        askIt();
        askIt();

        expect(RateLimiter::attempts('ai-answer:'.$this->reader->id))->toBe(1);
    });
});

describe('finding out it exists', function () {
    /*
     * Nobody types a question into a search box unless something told them
     * they could. The idle screen is the only place that can, and it is the
     * first thing every reader sees.
     */
    it('offers examples in the reader\'s own team name', function () {
        $this->reader->followedTeams()->attach($this->vols->id, ['position' => 1]);

        $examples = AskExamples::for($this->reader);

        expect($examples)->toContain('How many points did Tennessee score last season?')
            ->and($examples)->toContain('How many passing yards did Brandon Faizon throw last season?')
            // A leaderboard needs no name to resolve, so it is the one example
            // that cannot fail on a thin database.
            ->and($examples)->toContain('Who leads the country in rushing yards?');
    });

    it('falls back to Tennessee for a reader following nobody', function () {
        // The documented static example — the pilot audience is Tennessee
        // alumni, and a canned school is otherwise somebody's rival.
        expect(AskExamples::for($this->reader)[0])
            ->toBe('How many points did Tennessee score last season?');
    });

    it('drops the player example rather than offering one that would be declined', function () {
        /*
         * A suggestion the app then declines is worse than no suggestion: it
         * teaches that asking does not work, on the one attempt the reader was
         * ever going to make. The resolver refuses an ambiguous name, so an
         * example carrying one is a button that always fails.
         */
        Athlete::create(['id' => 900010, 'display_name' => 'Brandon Faizonaldo', 'last_name' => 'Faizonaldo', 'is_active' => true]);

        $this->reader->followedTeams()->attach($this->vols->id, ['position' => 1]);

        expect(AskExamples::for($this->reader))
            ->not->toContain('How many passing yards did Brandon Faizon throw last season?');
    });

    it('promises a guest nothing the screen cannot then do', function () {
        expect(AskExamples::for(null))->toBe([])
            ->and(StatAnswer::available(null))->toBeFalse();

        Livewire::test('search-page')
            ->assertDontSee('or ask a question')
            ->assertDontSee('Tap one');
    });

    it('says nothing about asking while the flag is closed', function () {
        config()->set('cfb.ai_answers', false);

        Livewire::actingAs($this->reader)->test('search-page')
            ->assertDontSee('Tap one')
            ->assertDontSee('or ask a question');
    });

    it('asks the example that was tapped, and puts it in the box', function () {
        // Following a team is what puts the player example at index 1.
        $this->reader->followedTeams()->attach($this->vols->id, ['position' => 1]);

        StatQuestion::fake([statIntent()]);

        Livewire::actingAs($this->reader)->test('search-page')
            ->assertSee('Tap one')
            ->call('askExample', 1)
            // The query moves too: the reader has to see what was asked, be
            // able to edit it, and share the URL.
            ->assertSet('q', 'How many passing yards did Brandon Faizon throw last season?')
            ->assertSee('3,412');
    });

    it('ignores an index nobody offered', function () {
        // A Livewire action is a public endpoint, so the button posts an INDEX
        // and the question is re-derived here — it can only ever ask ours.
        StatQuestion::fake([statIntent()]);

        Livewire::actingAs($this->reader)->test('search-page')
            ->call('askExample', 99)
            ->assertSet('q', '');

        StatQuestion::assertNeverPrompted();
    });
});

describe('a question outright, versus merely a long one', function () {
    it('reads a question mark or an interrogative anywhere as asking', function () {
        expect(StatAnswer::asksOutright('Mensah passing yards?'))->toBeTrue()
            ->and(StatAnswer::asksOutright('tell me who leads in sacks'))->toBeTrue();
    });

    it('does not read a fixture name as asking, however long it runs', function () {
        /*
         * Five words is also what a game is called. Only an outright question
         * is offered an answer while there are rows to read — the offer must
         * never stand in front of a result somebody was about to tap.
         */
        expect(StatAnswer::asksOutright('Tennessee Volunteers at Kentucky Wildcats'))->toBeFalse()
            ->and(StatAnswer::looksLikeAQuestion('Tennessee Volunteers at Kentucky Wildcats'))->toBeTrue();
    });
});

describe('the screen', function () {
    $question = 'How many passing yards did Brandon Faizon throw this season?';

    it('offers only where search came back empty, and never to a guest', function () use ($question) {
        // Strictly additive: the offer cannot stand in front of a result
        // somebody was about to tap.
        Livewire::test('search-page')->set('q', $question)->assertDontSee('Look it up');

        Livewire::actingAs($this->reader)->test('search-page')
            ->set('q', $question)
            ->assertSee('Look it up')
            // A name is not a question and must cost nothing.
            ->set('q', 'Tennessee')
            ->assertDontSee('Look it up');
    });

    it('stands down whenever ordinary search found something', function () {
        /*
         * The property that makes this strictly additive: the offer can never
         * stand in front of a result somebody was about to tap. This query is
         * question-shaped by the word count AND matches a real game, which is
         * the only overlap where the two gates disagree.
         */
        $matching = 'Tennessee Volunteers at Kentucky Wildcats';

        expect(StatAnswer::looksLikeAQuestion($matching))->toBeTrue();

        Livewire::actingAs($this->reader)->test('search-page')
            ->set('q', $matching)
            // The group heading, not the row: rows render a DERIVED short name.
            ->assertSee('Games')
            ->assertDontSee('Look it up');
    });

    it('renders the answer above the results, then retires it with the question', function () use ($question) {
        StatQuestion::fake([statIntent()]);

        Livewire::actingAs($this->reader)->test('search-page')
            ->set('q', $question)
            ->call('ask')
            ->assertSee('3,412')
            ->assertSee('Passing Yards')
            /*
             * The answer is pinned to the question that produced it. An old
             * number sitting under a new question is indistinguishable from a
             * wrong answer, and no `updated` hook covers every way `q` moves.
             */
            ->set('q', 'Kentucky')
            ->assertDontSee('3,412');
    });

    it('says so rather than quietly hiding the offer at the cap', function () use ($question) {
        for ($i = 0; $i < StatAnswer::DAILY_CAP; $i++) {
            RateLimiter::hit('ai-answer:'.$this->reader->id, 86400);
        }

        Livewire::actingAs($this->reader)->test('search-page')
            ->set('q', $question)
            ->assertDontSee('Look it up')
            ->assertSee('questions for today');
    });
});

describe('why it went unanswered', function () {
    /*
     * The reason has always existed — every refusal branch writes one — and
     * the surface used to destructure the answer alone and drop it. Twelve
     * distinct causes arrived at one sentence on screen and at nothing at all
     * in the log, so "the AI questions do not work" was un-diagnosable from
     * either end. What follows holds both halves down: the KIND reaches the
     * screen, the REASON reaches the log, and neither carries the other's job.
     */
    it('calls a spent cap the reader\'s, not a gap in our data', function () {
        for ($i = 0; $i < StatAnswer::DAILY_CAP; $i++) {
            RateLimiter::hit('ai-answer:'.$this->reader->id, 86400);
        }

        [$answer, $reason, $decline] = askIt();

        expect($answer)->toBeNull()
            ->and($reason)->toContain('daily cap')
            ->and($decline)->toBe(StatAnswer::DECLINE_CAPPED);
    });

    it('calls a refused budget ours', function () {
        config()->set('cfb.ai_monthly_budget', 1);
        AiSpend::create([
            'model' => AiModel::Haiku45->value,
            'feature' => 'answer',
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'cost' => 1.5,
        ]);

        [$answer, $reason, $decline] = askIt();

        expect($answer)->toBeNull()
            ->and($reason)->toContain('monthly AI budget is spent')
            ->and($decline)->toBe(StatAnswer::DECLINE_UNAVAILABLE);
    });

    it('calls a call that never came back ours', function () {
        StatQuestion::fake(fn () => throw new RuntimeException('gateway exploded'));

        [$answer, $reason, $decline] = askIt();

        expect($answer)->toBeNull()
            ->and($reason)->toBe('The classifier did not answer')
            ->and($decline)->toBe(StatAnswer::DECLINE_UNAVAILABLE);
    });

    it('calls a number we do not hold a fact about the question', function () {
        // The honest half of the split: nothing is broken here, and saying so
        // is a real answer rather than an apology.
        StatQuestion::fake([statIntent(['metric' => 'rushing.rushingYards'])]);

        [$answer, $reason, $decline] = askIt('How many rushing yards did Brandon Faizon get this season?');

        expect($answer)->toBeNull()
            ->and($reason)->toContain('We hold no')
            ->and($decline)->toBe(StatAnswer::DECLINE_DATA);
    });

    it('never pairs a resolved answer with a decline', function () {
        // askState() matches on the kind with no default arm, which is only
        // safe while this holds.
        StatQuestion::fake([statIntent()]);

        [$answer, , $decline] = askIt();

        expect($answer)->not->toBeNull()->and($decline)->toBe(StatAnswer::RESOLVED);
    });
});

describe('the log line nobody reads until it matters', function () {
    $question = 'How many passing yards did Brandon Faizon throw this season?';

    it('logs a call we could not make as a warning', function () use ($question) {
        StatQuestion::fake(fn () => throw new RuntimeException('gateway exploded'));

        Log::spy();

        Livewire::actingAs($this->reader)->test('search-page')->set('q', $question)->call('ask');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Stat question not answered.'
                && $context['failure'] === StatAnswer::DECLINE_UNAVAILABLE
                && $context['detail'] === 'The classifier did not answer');
    });

    it('logs a refused budget as a warning, naming the wall', function () use ($question) {
        config()->set('cfb.ai_monthly_budget', 1);
        AiSpend::create([
            'model' => AiModel::Haiku45->value,
            'feature' => 'answer',
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'cost' => 1.5,
        ]);

        Log::spy();

        Livewire::actingAs($this->reader)->test('search-page')->set('q', $question)->call('ask');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Stat question not answered.'
                && $context['failure'] === StatAnswer::DECLINE_UNAVAILABLE
                && str_contains($context['detail'], 'monthly AI budget is spent'));
    });

    it('logs a capped reader, but not as something broken', function () use ($question) {
        for ($i = 0; $i < StatAnswer::DAILY_CAP; $i++) {
            RateLimiter::hit('ai-answer:'.$this->reader->id, 86400);
        }

        Log::spy();

        Livewire::actingAs($this->reader)->test('search-page')->set('q', $question)->call('ask');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Stat question not answered.'
                && $context['failure'] === StatAnswer::DECLINE_CAPPED
                && str_contains($context['detail'], 'daily cap'));

        Log::shouldNotHaveReceived('warning');
    });

    it('logs a number we do not hold, so silence means nobody asked', function () use ($question) {
        StatQuestion::fake([statIntent(['metric' => 'rushing.rushingYards'])]);

        Log::spy();

        Livewire::actingAs($this->reader)->test('search-page')->set('q', $question)->call('ask');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Stat question not answered.'
                && $context['failure'] === StatAnswer::DECLINE_DATA
                && str_contains($context['detail'], 'We hold no'));
    });

    it('says nothing at all when the answer landed', function () use ($question) {
        StatQuestion::fake([statIntent()]);

        Log::spy();

        Livewire::actingAs($this->reader)->test('search-page')->set('q', $question)->call('ask');

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
    });

    it('keeps the reader out of it', function () use ($question) {
        /*
         * AGGREGATE, deliberately. This layer's telemetry carries no question
         * text and no user id, and a log of what people typed into a search
         * box is a log somebody then has to be trusted with. The two keys are
         * the whole context, and they are the same two StatAnswer's own
         * classifier line writes — one grep answers "why did asks stop".
         */
        StatQuestion::fake(fn () => throw new RuntimeException('gateway exploded'));

        Log::spy();

        Livewire::actingAs($this->reader)->test('search-page')->set('q', $question)->call('ask');

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($question): bool {
                $written = $message.' '.implode(' ', $context);

                return array_keys($context) === ['failure', 'detail']
                    && ! str_contains($written, $question)
                    && ! str_contains($written, 'Faizon')
                    && ! str_contains($written, (string) $this->reader->id)
                    && ! str_contains($written, (string) $this->reader->email);
            });
    });
});

describe('what a failed call costs', function () {
    it('does not spend a question on a call that never came back', function () {
        /*
         * The cap counts CALLS, and an outage is not one. A reader who asked
         * during a provider failure used to be spent down toward "capped"
         * without ever seeing an answer — and both states then rendered the
         * same silence, so there was nothing on screen to tell them apart.
         */
        StatQuestion::fake(fn () => throw new RuntimeException('gateway exploded'));

        askIt();

        expect(RateLimiter::attempts('ai-answer:'.$this->reader->id))->toBe(0)
            ->and(StatAnswer::capped($this->reader))->toBeFalse();
    });

    it('still charges a call that came back, and still charges it once', function () {
        // The other half: moving the hit must not make asking free, and a
        // re-ask served from the intent cache must stay free.
        StatQuestion::fake([statIntent()]);

        askIt();
        askIt();

        expect(RateLimiter::attempts('ai-answer:'.$this->reader->id))->toBe(1);
    });
});

describe('the sentence the reader gets', function () {
    $question = 'How many passing yards did Brandon Faizon throw this season?';

    it('tells a number we do not hold apart from a call that fell over', function () use ($question) {
        /*
         * The whole complaint in one test. A data miss is a real answer and
         * says so; an operational miss owns the failure and tells the reader
         * their question was fine — rewording it would not have helped, and
         * the old single line sent them off to try.
         */
        StatQuestion::fake([statIntent(['metric' => 'rushing.rushingYards'])]);

        Livewire::actingAs($this->reader)->test('search-page')
            ->set('q', $question)
            ->call('ask')
            ->assertSee(Voice::line('search.ask.none', for: $this->reader))
            ->assertDontSee(Voice::line('search.ask.unavailable', for: $this->reader));

        StatQuestion::fake(fn () => throw new RuntimeException('gateway exploded'));

        Livewire::actingAs($this->reader)->test('search-page')
            // A different question: the same one is answered from the intent
            // cache and would never reach the provider at all.
            ->set('q', 'How many rushing yards did Brandon Faizon get this season?')
            ->call('ask')
            ->assertSee(Voice::line('search.ask.unavailable', for: $this->reader))
            ->assertDontSee(Voice::line('search.ask.none', for: $this->reader));
    });

    it('speaks the operational miss in every register', function () {
        // Fall DOWN the ladder, never up: a line defined only in one register
        // reaches nobody who did not ask for it.
        $lines = (new ReflectionClass(Voice::class))->getConstant('LINES');

        expect($lines['search.ask.unavailable'])->toHaveKeys(['pg', 'pg13', 'r']);

        foreach ($lines['search.ask.unavailable'] as $register => $line) {
            // Search is FACTUAL — the chrome may have a voice, but nothing
            // here roasts the reader whose question was not the problem.
            expect($line)->not->toBe('')
                ->and($line)->not->toBe($lines['search.ask.none'][$register]);
        }
    });

    it('sends a reader who ran out mid-ask to the cap, not to an apology', function () use ($question) {
        for ($i = 0; $i < StatAnswer::DAILY_CAP; $i++) {
            RateLimiter::hit('ai-answer:'.$this->reader->id, 86400);
        }

        Livewire::actingAs($this->reader)->test('search-page')
            ->set('q', $question)
            ->call('ask')
            ->assertSee('questions for today')
            ->assertDontSee(Voice::line('search.ask.none', for: $this->reader))
            ->assertDontSee(Voice::line('search.ask.unavailable', for: $this->reader));
    });

    it('shows the ask is in flight on both surfaces', function () use ($question) {
        /*
         * The ask is a ~4s synchronous call inside the Livewire round trip —
         * three of them in one day were the slowest requests the app served.
         * Deliberately NOT moved to a queue: the answer is pinned to the
         * question that produced it, and an async answer would have to
         * re-establish that pin. So the screen has to say it is working.
         */
        foreach (['search-page', 'search-panel'] as $screen) {
            $html = Livewire::actingAs($this->reader)->test($screen)->set('q', $question)->html();

            expect($html)->toContain('wire:target="ask"')
                ->and($html)->toContain('wire:loading.attr="disabled"');
        }
    });
});
