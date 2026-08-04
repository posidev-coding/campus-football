<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team's season totals for one statistical category.
 *
 * `stats` is keyed by ESPN's stat name, and each entry carries the display
 * string, the raw value, ESPN's own NATIONAL RANK for that stat, and a human
 * label. The rank is the interesting part: ESPN has already ranked all 136 FBS
 * teams on every stat it publishes, so the national stats screen is a read
 * rather than a computation.
 */
#[Fillable(['team_id', 'season_year', 'season_type', 'category', 'stats'])]
class TeamSeasonStat extends Model
{
    protected function casts(): array
    {
        return ['stats' => 'array'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * One stat, normalized.
     *
     * Tolerates the flat scalar shape this column held before ranks were kept,
     * so a page rendered midway through a re-sync degrades to showing the value
     * without a rank rather than erroring on a string offset.
     *
     * @return array{display:?string, value:?float, rank:?int, label:string}
     */
    public function stat(string $name): array
    {
        $raw = $this->stats[$name] ?? null;

        if (! is_array($raw)) {
            return [
                'display' => $raw === null ? null : (string) $raw,
                'value' => is_numeric($raw) ? (float) $raw : null,
                'rank' => null,
                'label' => str($name)->headline()->toString(),
            ];
        }

        return [
            'display' => $raw['display'] ?? null,
            'value' => isset($raw['value']) ? (float) $raw['value'] : null,
            'rank' => $raw['rank'] ?? null,
            'label' => $raw['label'] ?? str($name)->headline()->toString(),
        ];
    }

    /**
     * Every stat in this category, normalized.
     *
     * Named `entries()` rather than the more obvious `all()`, which would
     * collide with Eloquent's static `Model::all()` and fatal on load.
     *
     * @return list<array{name:string, display:?string, value:?float, rank:?int, label:string}>
     */
    public function entries(): array
    {
        return collect(array_keys($this->stats ?? []))
            ->map(fn (string $name) => ['name' => $name] + $this->stat($name))
            ->values()
            ->all();
    }
}
