<?php

use App\Actions\RecordAiSpend;
use App\Enums\AiModel;
use App\Exceptions\AiBudgetExceeded;
use App\Filament\Widgets\AiSpendWidget;
use App\Jobs\Middleware\ThrottleAi;
use App\Models\AiSpend;
use App\Models\User;
use App\Support\AiBudget;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

/*
 * The enforced ceiling.
 *
 * The projection is ~$9/month against a $25 target, so steady state has two to
 * three times the headroom it needs. That is not what this is for: the real
 * risk is a retry storm or a runaway loop, and neither announces itself until
 * the bill arrives. Same house pattern as mail_daily_budget, sms_daily_budget
 * and ESPN_RATE_LIMIT — THE BUDGET IS OURS, NOT THEIRS.
 */

beforeEach(function () {
    config(['cfb.ai_enabled' => true, 'cfb.ai_monthly_budget' => 25.0]);
    $this->travelTo('2026-09-15 12:00:00');
});

/** Spend a known amount this month. */
function spend(float $dollars, string $feature = 'answers'): AiSpend
{
    return AiSpend::create([
        'model' => AiModel::Haiku45,
        'feature' => $feature,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cost' => $dollars,
    ]);
}

describe('the rate card', function () {
    it('prices a call the way the pricing page does', function () {
        // Verified against platform.claude.com/docs/en/about-claude/pricing
        // on 2026-08-24. 1M in + 1M out at Haiku's $1/$5.
        expect(AiModel::Haiku45->cost(1_000_000, 1_000_000))->toBe(6.0)
            ->and(AiModel::Sonnet5->cost(1_000_000, 1_000_000))->toBe(12.0);
    });

    it('holds Sonnet 5 at the price that became permanent', function () {
        // $2/$10 launched as introductory "through August 31, 2026" and is now
        // the standard price — the rise to $3/$15 was cancelled. A stale rate
        // here under-reports every recap by a third, so it is pinned.
        expect(AiModel::Sonnet5->inputRate())->toBe(2.0)
            ->and(AiModel::Sonnet5->outputRate())->toBe(10.0);
    });

    it('prices cache reads and writes off the INPUT rate, not as input', function () {
        // A read is a tenth and a 5-minute write is a quarter more. Folding
        // them into input tokens misprices a cached call in both directions
        // at once.
        expect(AiModel::Haiku45->cost(0, 0, cacheWriteTokens: 1_000_000))->toBe(1.25)
            ->and(AiModel::Haiku45->cost(0, 0, cacheReadTokens: 1_000_000))->toBe(0.1);
    });

    it('halves everything on the batch API', function () {
        expect(AiModel::Sonnet5->cost(1_000_000, 1_000_000, batch: true))->toBe(6.0);
    });

    it('keeps enough precision to see a real call', function () {
        // A Haiku classification is about $0.002. Rounded to cents, most of
        // the month's calls would record as free.
        expect(AiModel::Haiku45->cost(1_800, 150))->toBe(0.00255);
    });

    it('is a bounded list, because what cannot be costed cannot be capped', function () {
        expect(array_map(fn (AiModel $m) => $m->value, AiModel::cases()))
            ->toBe(['claude-haiku-4-5', 'claude-sonnet-5']);
    });
});

describe('the ledger', function () {
    it('computes the cost once, at the only doorway', function () {
        $row = app(RecordAiSpend::class)->handle(AiModel::Sonnet5, 'recaps', 3_900, 520);

        expect((float) $row->cost)->toBe(AiModel::Sonnet5->cost(3_900, 520))
            ->and($row->model)->toBe(AiModel::Sonnet5)
            ->and($row->feature)->toBe('recaps');
    });

    it('sums exactly, because the column is decimal and not a float', function () {
        // 0.1 + 0.2 in binary floating point is famously not 0.3, and a
        // ceiling comparison is exactly where that would bite.
        foreach ([0.1, 0.2, 0.3] as $dollars) {
            spend($dollars);
        }

        expect(app(AiBudget::class)->spent())->toBe(0.6);
    });

    it('counts the calendar month in the league timezone, not UTC', function () {
        /*
         * The boundary, both sides. 02:00 UTC on October 1st is 22:00 ET on
         * September 30th — September's money. Charging it to October's ceiling
         * is a silent four-hour error, not an exception: `created_at` is
         * stored UTC and a Carbon carrying an ET timezone binds as its local
         * wall time unless it is converted back.
         */
        $this->travelTo('2026-10-01 02:00:00');
        spend(5.0);

        $this->travelTo('2026-10-01 12:00:00');
        expect(app(AiBudget::class)->spent())->toBe(0.0);

        // ...and a call after ET midnight is October's.
        $this->travelTo('2026-10-01 05:00:00');
        spend(3.0);

        $this->travelTo('2026-10-01 12:00:00');
        expect(app(AiBudget::class)->spent())->toBe(3.0);
    });

    it('carries no prompt, no completion and no user', function () {
        // What was SAID is not the budget's business, and a ledger that stored
        // it would be a transcript nobody meant to keep.
        expect(Schema::getColumnListing('ai_spend'))->toBe([
            'id', 'model', 'feature', 'input_tokens', 'output_tokens',
            'cache_write_tokens', 'cache_read_tokens', 'batch', 'cost',
            'created_at', 'updated_at',
        ]);
    });
});

describe('the ceiling', function () {
    it('allows a call under budget', function () {
        spend(9.0);

        $budget = app(AiBudget::class);

        expect($budget->allows())->toBeTrue()
            ->and($budget->remaining())->toBe(16.0)
            ->and($budget->refusal())->toBeNull();
    });

    it('refuses once the month is spent', function () {
        spend(25.0);

        $budget = app(AiBudget::class);

        expect($budget->allows())->toBeFalse()
            ->and($budget->remaining())->toBe(0.0)
            ->and($budget->refusal())->toContain('monthly AI budget is spent');
    });

    it('refuses everything while the master switch is off, budget or not', function () {
        // A caller that checked only the budget would happily spend money with
        // the feature switched off, which is why allows() answers both.
        config(['cfb.ai_enabled' => false]);

        expect(app(AiBudget::class)->allows())->toBeFalse()
            ->and(app(AiBudget::class)->refusal())->toContain('switched off');
    });

    it('treats a budget of zero as uncapped, the mail convention', function () {
        config(['cfb.ai_monthly_budget' => 0]);
        spend(1_000.0);

        expect(app(AiBudget::class)->allows())->toBeTrue()
            ->and(app(AiBudget::class)->remaining())->toBeNull();
    });

    it('starts a new month clean', function () {
        spend(25.0);
        expect(app(AiBudget::class)->allows())->toBeFalse();

        $this->travelTo('2026-10-01 12:00:00');
        expect(app(AiBudget::class)->allows())->toBeTrue();
    });
});

describe('the job middleware', function () {
    it('lets a job through when there is money', function () {
        $ran = false;

        (new ThrottleAi)->handle(new stdClass, function () use (&$ran) {
            $ran = true;

            return 'done';
        });

        expect($ran)->toBeTrue();
    });

    it('fails loudly rather than releasing for a month', function () {
        // Its three siblings RELEASE, because their window is a day and
        // tomorrow is a fine time to send a newsletter. This window is a
        // month: a released job would park past any sane retry_until and the
        // "recovery" would be a job that silently expired.
        spend(25.0);

        expect(fn () => (new ThrottleAi)->handle(new stdClass, fn () => 'done'))
            ->toThrow(AiBudgetExceeded::class, 'monthly AI budget is spent');
    });

    it('fails when the layer is off, because the caller should not have dispatched', function () {
        config(['cfb.ai_enabled' => false]);

        expect(fn () => (new ThrottleAi)->handle(new stdClass, fn () => 'done'))
            ->toThrow(AiBudgetExceeded::class);
    });
});

describe('the flags', function () {
    it('reads config, so a flip is an environment change', function () {
        config(['cfb.ai_answers' => true, 'cfb.ai_recaps' => false]);

        expect(Feature::active('ai-answers'))->toBeTrue()
            ->and(Feature::active('ai-recaps'))->toBeFalse();
    });

    it('closes everything at once from the master switch', function () {
        config(['cfb.ai_enabled' => false, 'cfb.ai_answers' => true, 'cfb.ai_recaps' => true]);

        expect(Feature::active('ai-answers'))->toBeFalse()
            ->and(Feature::active('ai-recaps'))->toBeFalse();
    });

    it('does not read the budget, so no stale row can outlive it', function () {
        // Pennant's database driver persists a row per resolve. A flag that
        // flipped with spend would strand one the moment the month ran out and
        // answer from it afterwards.
        config(['cfb.ai_answers' => true]);
        spend(25.0);

        expect(Feature::active('ai-answers'))->toBeTrue()
            ->and(app(AiBudget::class)->allows())->toBeFalse();
    });
});

describe('the panel', function () {
    it('shows month-to-date spend against the ceiling', function () {
        $admin = User::factory()->create();
        $admin->forceFill(['admin' => true])->save();

        spend(6.0, 'recaps');
        spend(3.0, 'answers');

        Livewire::actingAs($admin)->test(AiSpendWidget::class)
            ->assertOk()
            ->assertSee('$9.00')
            ->assertSee('$16.00 left of $25.00')
            ->assertSee('recaps');
    });

    it('says plainly when the layer is off', function () {
        config(['cfb.ai_enabled' => false]);

        $admin = User::factory()->create();
        $admin->forceFill(['admin' => true])->save();

        Livewire::actingAs($admin)->test(AiSpendWidget::class)
            ->assertOk()
            ->assertSee('AI_ENABLED is false');
    });
});
