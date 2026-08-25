<?php

namespace App\Enums;

/**
 * The models this application is allowed to call, and what they cost.
 *
 * BOUNDED, like every other vocabulary here: adding a model is a code review,
 * not a config edit, because each one has a price and the budget is only as
 * honest as this list. A call to a model that is not a case cannot be costed,
 * and something that cannot be costed cannot be capped.
 *
 * Rates are USD per MILLION tokens, verified against
 * platform.claude.com/docs/en/about-claude/pricing on 2026-08-24:
 *
 *     claude-sonnet-5    $2 in · $10 out · $2.50 5m-write · $0.20 read
 *     claude-haiku-4-5   $1 in · $5  out · $1.25 5m-write · $0.10 read
 *
 * Sonnet 5's $2/$10 launched as introductory pricing "through August 31, 2026".
 * IT IS NOW THE STANDARD PRICE — the scheduled rise to $3/$15 on September 1
 * was cancelled, confirmed on the pricing page. Worth writing down because the
 * old schedule is still in a lot of secondary sources, and a stale table here
 * under-reports every recap by a third.
 *
 * The multipliers are ratios of the base input rate and are stable across
 * models: 5-minute cache write 1.25x, 1-hour cache write 2x, cache read 0.1x.
 * Batch halves input and output both.
 */
enum AiModel: string
{
    /** Classification and intent. Cheap enough to be the default. */
    case Haiku45 = 'claude-haiku-4-5';

    /** Anything a person reads as prose. */
    case Sonnet5 = 'claude-sonnet-5';

    public const CACHE_WRITE_MULTIPLIER = 1.25;

    public const CACHE_READ_MULTIPLIER = 0.1;

    public const BATCH_MULTIPLIER = 0.5;

    public function label(): string
    {
        return match ($this) {
            self::Haiku45 => 'Haiku 4.5',
            self::Sonnet5 => 'Sonnet 5',
        };
    }

    /** USD per million input tokens. */
    public function inputRate(): float
    {
        return match ($this) {
            self::Haiku45 => 1.0,
            self::Sonnet5 => 2.0,
        };
    }

    /** USD per million output tokens. */
    public function outputRate(): float
    {
        return match ($this) {
            self::Haiku45 => 5.0,
            self::Sonnet5 => 10.0,
        };
    }

    /**
     * What one call cost, in dollars.
     *
     * Cache reads and writes are priced off the INPUT rate, not billed as
     * ordinary input — a read is a tenth of the price and a write is a quarter
     * more, so folding them into `$inputTokens` would misprice a cached call in
     * both directions at once.
     */
    public function cost(
        int $inputTokens,
        int $outputTokens,
        int $cacheWriteTokens = 0,
        int $cacheReadTokens = 0,
        bool $batch = false,
    ): float {
        $perToken = fn (float $perMillion): float => $perMillion / 1_000_000;

        $cost = $inputTokens * $perToken($this->inputRate())
            + $outputTokens * $perToken($this->outputRate())
            + $cacheWriteTokens * $perToken($this->inputRate() * self::CACHE_WRITE_MULTIPLIER)
            + $cacheReadTokens * $perToken($this->inputRate() * self::CACHE_READ_MULTIPLIER);

        if ($batch) {
            $cost *= self::BATCH_MULTIPLIER;
        }

        // Six places, matching the column. A Haiku classification is about
        // $0.002, so rounding to cents would record most calls as free.
        return round($cost, 6);
    }
}
