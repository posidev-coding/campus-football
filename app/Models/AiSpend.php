<?php

namespace App\Models;

use App\Enums\AiModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One model call and what it cost. See the migration for why this is exact
 * decimal and why it carries no prompt, no completion and no user.
 */
#[Fillable([
    'model', 'feature', 'input_tokens', 'output_tokens',
    'cache_write_tokens', 'cache_read_tokens', 'batch', 'cost',
])]
class AiSpend extends Model
{
    protected $table = 'ai_spend';

    protected function casts(): array
    {
        return [
            'model' => AiModel::class,
            'batch' => 'boolean',
            'cost' => 'decimal:6',
        ];
    }

    /**
     * The calendar month to date, in the league's timezone.
     *
     * ET rather than UTC for the reason everything else here is: the month has
     * to end when a person would say it ended, and a call at 22:00 ET on the
     * 30th is not next month's spend.
     *
     * `->utc()` IS LOAD-BEARING, and leaving it off is a silent four-hour
     * error rather than an exception. `created_at` is stored UTC, but a Carbon
     * carrying an ET timezone formats to its LOCAL wall time when Eloquent
     * binds it — so the comparison would be "2026-10-01 00:00:00" against UTC
     * values, and every call made in the last four hours of September would be
     * charged to October's ceiling.
     */
    public function scopeThisMonth(Builder $query): Builder
    {
        $start = now()->timezone(config('cfb.timezone'))->startOfMonth()->utc();

        return $query->where('created_at', '>=', $start);
    }
}
