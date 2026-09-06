<?php

use App\Ai\Agents\OpsQuestion;
use App\Enums\AiModel;
use App\Filament\Pages\HealthDashboard;
use App\Models\ActivityEvent;
use App\Models\AiSpend;
use App\Models\Contest;
use App\Models\Game;
use App\Models\PageViewDaily;
use App\Models\Slate;
use App\Models\SlateGame;
use App\Models\User;
use App\Models\UserDay;
use App\Support\AnalyticsCatalog;
use App\Support\AnalyticsWindow;
use App\Support\Cadence;
use App\Support\OpsAnswer;
use Livewire\Livewire;

/*
 * Phase 11 of docs/plans/analytics.md: an admin's sentence, answered out of
 * the catalog.
 *
 * THE MODEL NEVER EMITS A NUMBER AND NEVER EMITS SQL. It names one key from a
 * closed list and one window token; the application runs the query. That is
 * the house rule, and it is the reason the plan turned down a plugin that
 * sends a schema out, executes the SQL that comes back, and hands the rows to
 * a model for narrative.
 *
 * So every test below asks the same two questions the other two answers ask:
 * did the gate run BEFORE the call, and did our own code get the last word.
 */

beforeEach(function () {
    config()->set('cfb.ai_enabled', true);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['admin' => true])->save();

    $this->travelTo('2026-09-05 18:00:00');
});

/** A well-formed intent, overridable per assertion. */
function opsIntent(array $overrides = []): array
{
    return [
        'answerable' => true,
        'question' => 'actives',
        'range' => '28d',
        'note' => '',
        ...$overrides,
    ];
}

function askOps(string $question = 'How many people were here this week?'): array
{
    return app(OpsAnswer::class)->for($question);
}

describe('the vocabulary', function () {
    it('renders something for every key the model may name', function () {
        /*
         * ONE LIST FEEDS BOTH ENDS, the HelpTopics rule: the schema enum and
         * the resolver read the same constant, so a key the model can name and
         * the app cannot run is impossible by construction.
         *
         * WHAT THIS SWEEPS IS THE RENDERING, not the running. A first version
         * asserted only that `answer()` was not null, and passed while FIVE of
         * the eleven drew an empty modal — they answer with a bare list rather
         * than a map, and a renderer that only walked maps had nothing to say
         * about the cohort grid, the Saturdays, the weekday heat or the
         * pick'em rows. Asserting the query ran is not asserting the answer
         * arrived.
         */
        $reader = User::factory()->create();

        UserDay::factory()->create(['user_id' => $reader->id, 'day' => '2026-09-05']);
        PageViewDaily::factory()->create(['day' => '2026-09-05', 'views' => 3]);
        ActivityEvent::factory()->create(['user_id' => $reader->id, 'occurred_at' => '2026-09-05 16:00:00']);

        // ...and a slate on the Saturday being played, or `pickem_health` is
        // legitimately empty and the sweep proves nothing about it.
        $contest = Contest::factory()->create(['season_year' => 2026]);
        $slate = Slate::factory()->create([
            'contest_id' => $contest->id,
            'saturday' => Cadence::currentSaturday()->toDateString(),
        ]);
        SlateGame::factory()->create([
            'slate_id' => $slate->id,
            'game_id' => Game::factory()->create([
                'kickoff_at' => Cadence::currentSaturday()->setTime(16, 0),
            ])->id,
        ]);

        foreach (AnalyticsCatalog::keys() as $key) {
            OpsQuestion::fake([opsIntent(['question' => $key])]);

            [$answer, $reason] = askOps('What about '.$key.'?');

            expect($reason)->toBe('resolved')
                ->and($answer['title'])->not->toBe('');

            expect($answer['rows'] === [] && $answer['tables'] === [])
                ->toBeFalse("`{$key}` answered with nothing to render");
        }
    });

    it('refuses a key nobody put in the list', function () {
        // A nearest match here is the whole failure mode: a question we do not
        // answer must read as one we do not answer.
        expect(app(AnalyticsCatalog::class)->answer('revenue'))->toBeNull();
    });

    it('names each question distinctly enough for a twelve-way choice', function () {
        // A vocabulary line that does not separate its near neighbors is where
        // a classifier misroutes first — traffic against actives against
        // routes, retention against saturday_retention.
        $summaries = collect(AnalyticsCatalog::QUESTIONS)->pluck('summary');

        expect($summaries->unique())->toHaveCount($summaries->count())
            ->and(AnalyticsCatalog::vocabulary())->toContain('`saturday_retention`');
    });
});

describe('the gates', function () {
    it('never prompts when the AI layer is off', function () {
        config()->set('cfb.ai_enabled', false);
        OpsQuestion::fake([opsIntent()]);

        expect(askOps()[0])->toBeNull();
        OpsQuestion::assertNeverPrompted();
    });

    it('never prompts for something that is not a question', function () {
        OpsQuestion::fake([opsIntent()]);

        expect(askOps('actives')[0])->toBeNull();
        OpsQuestion::assertNeverPrompted();
    });

    it('never prompts once the month is spent', function () {
        // The budget is the wall on this surface — there is no per-reader cap,
        // because the door is admin-only and the population is one person.
        config()->set('cfb.ai_monthly_budget', 1);

        AiSpend::create([
            'model' => AiModel::Haiku45->value,
            'feature' => 'ops',
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'cost' => 1.5,
        ]);

        OpsQuestion::fake([opsIntent()]);

        expect(askOps()[0])->toBeNull();
        OpsQuestion::assertNeverPrompted();
    });
});

describe('the answer', function () {
    it('runs the named question and hands back our own numbers', function () {
        foreach (User::factory()->count(10)->create() as $user) {
            foreach (['2026-09-04', '2026-09-05'] as $day) {
                UserDay::factory()->create(['user_id' => $user->id, 'day' => $day]);
            }
        }

        OpsQuestion::fake([opsIntent()]);

        [$answer, $reason] = askOps();

        expect($reason)->toBe('resolved')
            ->and($answer['key'])->toBe('actives')
            ->and($answer['title'])->toBe('Actives and stickiness')
            ->and($answer['asked'])->toBe('How many people were here this week?')
            ->and(collect($answer['rows'])->firstWhere('label', 'Mau')['value'])->toBe('10');
    });

    it('carries since, because a window is not the days it has data for', function () {
        /*
         * A 90-day count off a sensor that shipped a fortnight ago is a
         * two-week number wearing a three-month label. It is part of the
         * answer on this surface, not a footnote under it.
         */
        PageViewDaily::factory()->create(['day' => '2026-09-04', 'views' => 5]);

        OpsQuestion::fake([opsIntent(['question' => 'traffic', 'range' => '90d'])]);

        [$answer] = askOps('How much is being read?');

        expect($answer['since'])->toBe('2026-09-04')
            ->and($answer['range'])->toBe('90 days');
    });

    it('prints no range on a question that is not counted in days', function () {
        // `retention` is a cohort grid and `pickem_health` is one Saturday.
        // A "28 days" label over numbers that do not honor it is a lie the
        // heading tells for free.
        OpsQuestion::fake([opsIntent(['question' => 'retention'])]);

        [$answer] = askOps('Are the people who signed up in August still here?');

        expect($answer['range'])->toBeNull();
    });

    it('prints no data rather than a zero for a rate there are too few to divide', function () {
        // The catalog withholds a share below its floor, and a 0% is the most
        // confident possible rendering of "we cannot tell yet".
        OpsQuestion::fake([opsIntent()]);

        [$answer] = askOps();

        expect(collect($answer['rows'])->firstWhere('label', 'Stickiness 28d')['value'])->toBe('no data');
    });

    it('keeps a long list as a capped table rather than a hundred flattened lines', function () {
        PageViewDaily::factory()->create([
            'day' => '2026-09-04', 'route' => 'scoreboard', 'views' => 9,
        ]);

        OpsQuestion::fake([opsIntent(['question' => 'routes'])]);

        [$answer] = askOps('Which screens get opened?');

        $top = collect($answer['tables'])->firstWhere('label', 'Top');

        expect($top['columns'])->toBe(['Route', 'Views', 'Visitors'])
            ->and($top['rows'][0]['route'])->toBe('scoreboard');
    });

    it('says a question found nothing rather than drawing an empty box', function () {
        /*
         * Reachable: pick'em health on a week with no slate published answers
         * with an empty list. "It ran and found nothing" and "it broke" look
         * identical in an empty modal, and only one of them is true.
         */
        OpsQuestion::fake([opsIntent(['question' => 'pickem_health'])]);

        Livewire::actingAs($this->admin)
            ->test(HealthDashboard::class)
            ->callAction('askTheData', ['question' => "How is this Saturday's pick'em going?"])
            ->assertMountedActionModalSee('Nothing to report');
    });
});

describe('a miss', function () {
    it('returns null when the classifier declines', function () {
        OpsQuestion::fake([opsIntent(['answerable' => false, 'note' => 'That is about football.'])]);

        [$answer, $reason] = askOps('Who is going to win on Saturday?');

        expect($answer)->toBeNull()
            ->and($reason)->toBe('That is about football.');
    });

    it('returns null for a key the catalog does not hold', function () {
        // The schema enumerates the keys, so this needs the model to answer
        // outside its own schema — which is exactly the case the resolver must
        // not paper over with a nearest match.
        OpsQuestion::fake([opsIntent(['question' => 'revenue'])]);

        [$answer, $reason] = askOps();

        expect($answer)->toBeNull()
            ->and($reason)->toContain('not a question we answer');
    });
});

describe('the door', function () {
    it('is hidden entirely when the AI layer is off', function () {
        // A door that never opens is worse than no door.
        config()->set('cfb.ai_enabled', false);

        Livewire::actingAs($this->admin)
            ->test(HealthDashboard::class)
            ->assertActionHidden('askTheData');
    });

    it('answers in the modal and leaves it open to be read', function () {
        foreach (User::factory()->count(10)->create() as $user) {
            UserDay::factory()->create(['user_id' => $user->id, 'day' => '2026-09-05']);
        }

        OpsQuestion::fake([opsIntent()]);

        Livewire::actingAs($this->admin)
            ->test(HealthDashboard::class)
            ->assertActionVisible('askTheData')
            ->callAction('askTheData', ['question' => 'How many people were here this week?'])
            // Still mounted: the action halts rather than closing on the one
            // thing the asker opened it for.
            ->assertActionMounted('askTheData')
            ->assertMountedActionModalSee('Actives and stickiness')
            ->assertMountedActionModalSee('Counted since 2026-09-05');
    });

    it('says one plain sentence on a miss, never the developer reason', function () {
        /*
         * The reason names budgets and classifier states. It belongs in the
         * log; the asker's answer to every one of them is the same sentence,
         * and the charts behind the modal answer more than the box can.
         */
        OpsQuestion::fake([opsIntent(['answerable' => false, 'note' => 'The budget is spent.'])]);

        Livewire::actingAs($this->admin)
            ->test(HealthDashboard::class)
            ->callAction('askTheData', ['question' => 'What did we spend on the model?'])
            ->assertMountedActionModalSee(OpsAnswer::MISS)
            ->assertMountedActionModalDontSee('The budget is spent.');
    });
});

describe('the window token', function () {
    it('falls back to the default rather than honoring a range nobody offers', function () {
        // Filters come off a URL and enums off a model; neither is trusted to
        // name a window this app does not have.
        OpsQuestion::fake([opsIntent(['question' => 'traffic', 'range' => '4000d'])]);

        [$answer] = askOps('How much is being read?');

        expect($answer['range'])->toBe(AnalyticsWindow::DEFAULT_DAYS.' days');
    });
});
