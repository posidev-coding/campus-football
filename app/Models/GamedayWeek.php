<?php

namespace App\Models;

use App\Enums\GamedayStatus;
use Database\Factories\GamedayWeekFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where College GameDay is broadcasting from on one Saturday.
 *
 * One row per `(season_year, saturday)` and never more, because the command
 * that writes it runs every morning Sunday through Thursday until the week
 * resolves. Five runs, one row.
 *
 * @property GamedayStatus $status
 */
class GamedayWeek extends Model
{
    /** @use HasFactory<GamedayWeekFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Mirrors the column defaults, so a row built in memory answers the same
     * way as one read back from MySQL.
     */
    protected $attributes = [
        'status' => GamedayStatus::Unknown->value,
    ];

    protected function casts(): array
    {
        return [
            'saturday' => 'date',
            'status' => GamedayStatus::class,
            'confidence' => 'float',
            'announced_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * THE ONLY DOORWAY, so the two guarantees hold for every caller — the
     * feed, the model fallback, and any backfill written later.
     *
     * 1. Idempotent on `(season_year, saturday)`. The command runs up to five
     *    mornings a week; it must update one row, not stack five.
     * 2. A CONFIRMED row is never overwritten. A human typing the campus is
     *    the final word, and a later run that disagrees is the run that is
     *    wrong — the same shape as WorkbookItem::propose() refusing to reopen
     *    a dismissal.
     *
     * `checked_at` still moves on a confirmed row: "we looked again and left
     * it alone" is worth being able to see.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(int $seasonYear, string $saturday, array $attributes): self
    {
        $existing = static::query()
            ->where('season_year', $seasonYear)
            ->whereDate('saturday', $saturday)
            ->first();

        if ($existing?->status === GamedayStatus::Confirmed) {
            $existing->forceFill(['checked_at' => now()])->save();

            return $existing;
        }

        if ($existing === null) {
            return static::create([
                ...$attributes,
                'season_year' => $seasonYear,
                'saturday' => $saturday,
                'checked_at' => now(),
            ]);
        }

        $existing->fill([...$attributes, 'checked_at' => now()])->save();

        return $existing;
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
