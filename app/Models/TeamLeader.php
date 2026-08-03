<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_id', 'season_year', 'season_type', 'category', 'athlete_id', 'value', 'display_value', 'rank'])]
class TeamLeader extends Model
{
    /**
     * The 14 categories ESPN publishes, verified live. Ordered as the team page
     * presents them: headline leaders first, then the counting stats.
     */
    public const CATEGORIES = [
        'passingLeader', 'rushingLeader', 'receivingLeader',
        'passingYards', 'rushingYards', 'receivingYards',
        'passingTouchdowns', 'rushingTouchdowns', 'receivingTouchdowns',
        'receptions', 'quarterbackRating',
        'totalTackles', 'sacks', 'interceptions',
    ];

    public static function label(string $category): string
    {
        return match ($category) {
            'passingLeader' => 'Passing',
            'rushingLeader' => 'Rushing',
            'receivingLeader' => 'Receiving',
            'passingYards' => 'Pass Yards',
            'rushingYards' => 'Rush Yards',
            'receivingYards' => 'Rec Yards',
            'passingTouchdowns' => 'Pass TD',
            'rushingTouchdowns' => 'Rush TD',
            'receivingTouchdowns' => 'Rec TD',
            'receptions' => 'Receptions',
            'quarterbackRating' => 'QBR',
            'totalTackles' => 'Tackles',
            'sacks' => 'Sacks',
            'interceptions' => 'INT',
            default => str($category)->headline()->toString(),
        };
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }
}
