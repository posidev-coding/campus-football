<?php

use App\Models\Conference;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Support\Scope;

beforeEach(function () {
    $this->sec = Conference::factory()->create(['id' => 8, 'name' => 'Southeastern Conference', 'short_name' => 'SEC', 'is_conference' => true]);
    $this->socon = Conference::factory()->create(['id' => 30, 'name' => 'Southern Conference', 'short_name' => 'SoCon', 'is_conference' => true]);

    Team::factory()->create(['id' => 61]);
    Team::factory()->create(['id' => 2000]);

    TeamSeason::create(['team_id' => 61, 'season_year' => 2025, 'conference_id' => 8, 'classification' => 'FBS']);
    TeamSeason::create(['team_id' => 2000, 'season_year' => 2025, 'conference_id' => 30, 'classification' => 'FCS']);
});

it('lists only FBS conferences by default', function () {
    $values = array_column(Scope::options(2025, top25: false), 'value');

    expect($values)->toContain('8')->not->toContain('30');
});

it('lists FCS conferences under a division group when FCS is in play', function () {
    $options = collect(Scope::options(2025, includeFcs: true, top25: false));

    // Every option carries the same shape, so the menu never guesses at keys.
    expect($options->firstWhere('value', Scope::FCS))->not->toBeNull()
        ->and($options->firstWhere('value', '8')['group'])->toBe('FBS')
        ->and($options->firstWhere('value', '30')['group'])->toBe('FCS')
        ->and($options->firstWhere('value', Scope::FBS)['group'])->toBeNull();

    // FBS conferences come before FCS ones — the order the headings render in.
    expect($options->search(fn ($o) => $o['value'] === '8'))
        ->toBeLessThan($options->search(fn ($o) => $o['value'] === '30'));
});

it('resolves an FCS conference id to its teams', function () {
    // The digit branch carries no classification clause, so an FCS conference
    // id already works — this pins that, since standings now leans on it.
    expect(Scope::teamIds('30', 2025))->toBe([2000])
        ->and(Scope::teamIds(Scope::FCS, 2025))->toBe([2000]);
});

it('keys the Top 25 cache by poll, so the November CFP switch lands instantly', function () {
    // Source pin: with the poll outside the key, the calendar's AP → CFP
    // flip served last week's AP list as "Top 25" for a full TTL.
    $source = file_get_contents(app_path('Support/Scope.php'));

    expect($source)->toContain('scope:top25:{$year}:{$poll}')
        // And the options key folds hasRankings in — the Remember::filled
        // class of guard, so the preseason poll un-greys Top 25 at once.
        ->and($source)->toContain("(\$hasRankings ? 'r' : 'nr')");
});
