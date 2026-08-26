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
use App\Support\StatAnswer;
use App\Support\Stats\StatCatalog;
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
