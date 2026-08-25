<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every model call this application makes, and what it cost.
 *
 * The ledger the monthly ceiling is enforced against. It exists because the
 * real budget risk here was never steady state — the projection is under
 * $10/month against a $25 target — it is a retry storm or a runaway loop, and
 * neither announces itself on a bill until the month is over.
 *
 * The house pattern, third time: `mail_daily_budget`, `sms_daily_budget` and
 * `ESPN_RATE_LIMIT` all say the same thing — THE BUDGET IS OURS, NOT THEIRS.
 * The Console's own spend limit is the outer wall; this is the one that can
 * refuse a single call before it is made.
 *
 * `cost` is DECIMAL, not float. MySQL's DECIMAL is exact and SUM() over it is
 * exact, which is what a ceiling comparison needs; six places because a Haiku
 * classification costs about $0.002 and two places would record most of the
 * month's calls as free.
 *
 * Aggregate by design: no prompt, no completion, no user. This table answers
 * "what did we spend" and nothing else — what was SAID is not the budget's
 * business, and a ledger that stored it would become a transcript nobody meant
 * to keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_spend', function (Blueprint $table) {
            $table->id();
            // An App\Enums\AiModel value. A model with no case cannot be
            // costed, and what cannot be costed cannot be capped.
            $table->string('model', 40);
            // Which line of the cost model this was — 'answers', 'recaps',
            // 'gameday'. Bounded by convention rather than by an enum, because
            // a new feature should not need a migration to start reporting.
            $table->string('feature', 40);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            // Priced off the INPUT rate at 1.25x and 0.1x, never as ordinary
            // input — folding them in misprices a cached call both ways.
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->boolean('batch')->default(false);
            $table->decimal('cost', 12, 6);
            $table->timestamps();

            // "What have we spent this month" is the only hot query, and the
            // one the ceiling is read from on every call.
            $table->index('created_at');
            // ...and the widget's breakdown by line.
            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_spend');
    }
};
