<?php

use App\Models\Team;
use App\Services\Nil\NilNewsProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('espn.http.rate_limit', 0);
    $this->team = Team::factory()->create(['id' => 61, 'display_name' => 'Georgia Bulldogs']);
});

it('surfaces NIL-related articles and drops the rest', function () {
    Http::fake(['*news*' => Http::response(['articles' => [
        ['headline' => 'Arbitrator allows Georgia players to receive NIL deals'],
        ['headline' => 'Georgia wins on a last-second field goal'],
        ['headline' => 'Booster collective raises $12m', 'description' => 'A donor collective.'],
    ]])]);

    $news = app(NilNewsProvider::class)->forTeam($this->team);

    expect($news)->toHaveCount(2)
        ->and($news->pluck('headline')->all())->not->toContain('Georgia wins on a last-second field goal');
});

it('matches NIL as a whole word, not inside other words', function () {
    // Otherwise this hits "Nil" inside ordinary words and surnames.
    Http::fake(['*news*' => Http::response(['articles' => [
        ['headline' => 'Coach Nilsson signs an extension'],
    ]])]);

    expect(app(NilNewsProvider::class)->forTeam($this->team))->toBeEmpty();
});

it('returns nothing rather than failing when the feed is unavailable', function () {
    Http::fake(['*news*' => Http::response('', 404)]);

    expect(app(NilNewsProvider::class)->forTeam($this->team))->toBeEmpty();
});
