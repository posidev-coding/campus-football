<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a game's team box score.
 */
#[Fillable(['game_id', 'team_id', 'stats', 'display_stats'])]
class GameTeamStat extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'display_stats' => 'array',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Stats in ESPN's published order, which `stats` alone cannot give us —
     * MySQL reorders JSON object keys, so the order lives in the
     * `display_stats` array and the values are looked up through it.
     *
     * @return list<array{name:string, label:string, value:string}>
     */
    public function ordered(): array
    {
        $stats = $this->stats ?? [];

        return collect($this->display_stats ?? array_keys($stats))
            ->map(fn (array|string $entry) => is_array($entry)
                ? $entry
                : ['name' => $entry, 'label' => $entry])
            ->filter(fn (array $entry) => isset($stats[$entry['name']]))
            ->map(fn (array $entry) => [
                'name' => $entry['name'],
                'label' => $entry['label'] ?? $entry['name'],
                'value' => (string) $stats[$entry['name']],
            ])
            ->values()
            ->all();
    }
}
