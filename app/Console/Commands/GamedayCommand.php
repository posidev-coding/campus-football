<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Enums\GamedayStatus;
use App\Models\GamedayWeek;
use App\Services\CfbCalendar;
use App\Services\GamedayFeed;
use App\Support\Gameday;
use App\Support\GamedayResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Where is College GameDay this Saturday?
 *
 * Runs Sunday through Thursday and STOPS FOR THE WEEK the moment a Saturday
 * resolves, so a normal week costs one or two runs rather than five. ESPN
 * announces the site about a week ahead, usually Sunday or Monday, which is
 * why the window opens on Sunday and why an empty Monday is not a failure.
 *
 * The feed is the only path today. Everything it produces still goes through
 * {@see GamedayResolver}, which decides against our own venues and games
 * whether the location is real — the feed is hand-maintained and currently
 * files Norman, Oklahoma under an LSU matchup, so first-party buys it nothing.
 */
class GamedayCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:gameday
        {--saturday= : The Saturday to resolve, default the upcoming one}
        {--force : Re-check a week that already resolved, and ignore the season gate}';

    protected $description = 'Resolve where College GameDay is broadcasting from this Saturday';

    public function handle(CfbCalendar $calendar, GamedayFeed $feed, GamedayResolver $resolver): int
    {
        $force = (bool) $this->option('force');

        if (! $force && ! $calendar->phase()->isLive()) {
            $this->info('Off-season — GameDay is not on the air, and the card does not render.');

            return self::SUCCESS;
        }

        $saturday = $this->targetSaturday();
        $year = $calendar->currentYear();

        $existing = GamedayWeek::query()
            ->where('season_year', $year)
            ->whereDate('saturday', $saturday->toDateString())
            ->first();

        if (! $force && $existing?->status->isKnown()) {
            // The whole point of stopping early: no request, no parse, no
            // chance of a later run disagreeing with a good answer.
            $this->info("{$saturday->toDateString()} already resolved to {$existing->site} — nothing to do.");

            return self::SUCCESS;
        }

        $this->trackRun('gameday', $year, function () use ($feed, $resolver, $saturday, $year, $existing): int {
            [$attributes, $reason] = $this->resolveWeek($feed, $resolver, $saturday, $existing);

            if ($attributes === null) {
                /*
                 * NEVER DOWNGRADE A GOOD ANSWER. A forced re-check whose feed
                 * is down must not replace Monday's resolved site with
                 * `unknown` — that is the no-defaults rule pointed at our own
                 * earlier work rather than at somebody else's feed.
                 */
                if ($existing?->status->isKnown()) {
                    $existing->forceFill(['checked_at' => now()])->save();
                    $this->warn("{$reason} — keeping the site already recorded.");

                    return 0;
                }

                GamedayWeek::record($year, $saturday->toDateString(), ['status' => GamedayStatus::Unknown]);
                $this->info("{$reason} — recorded as not yet announced.");

                return 0;
            }

            $week = GamedayWeek::record($year, $saturday->toDateString(), $attributes);

            $this->info("{$saturday->toDateString()}: {$week->site} — {$week->city}, {$week->state}");
            $this->line('  '.($week->game?->name ?? 'no game linked'));

            return 1;
        });

        return self::SUCCESS;
    }

    /**
     * Read the feed, then let our own data decide.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function resolveWeek(
        GamedayFeed $feed,
        GamedayResolver $resolver,
        CarbonImmutable $saturday,
        ?GamedayWeek $existing,
    ): array {
        $payload = $feed->payload();

        if ($payload === null) {
            return [null, 'The GameDay feed did not answer'];
        }

        $matchup = $feed->forSaturday($payload, $saturday);

        if ($matchup === null) {
            return [null, "The feed carries no cutoff for {$saturday->toDateString()}"];
        }

        $place = $resolver->parseLocation($matchup['location']);

        if ($place === null) {
            return [null, "Could not read a city and state from \"{$matchup['location']}\""];
        }

        $game = $resolver->resolve($place['city'], $place['state'], $saturday);

        if ($game === null) {
            return [null, "\"{$matchup['location']}\" matches no single game we hold that Saturday"];
        }

        return [[
            'site' => $game->venue?->name,
            'city' => $place['city'],
            'state' => $place['state'],
            'team_id' => $resolver->hostTeam($game)?->id,
            'game_id' => $game->id,
            'status' => GamedayStatus::Proposed,
            'source_url' => config('gameday.feed_url'),
            'payload_hash' => $feed->fingerprint($matchup),
            // First learned, not last seen. `checked_at` is the one that moves.
            'announced_at' => $existing?->announced_at ?? now(),
        ], 'resolved'];
    }

    /**
     * The Saturday to resolve — {@see Gameday::saturday()} unless pinned.
     *
     * Deliberately not computed here: the card reads the same clock, and a
     * card looking at a different Saturday than the command wrote would not
     * look broken, only permanently empty.
     */
    private function targetSaturday(): CarbonImmutable
    {
        if ($pinned = $this->option('saturday')) {
            return CarbonImmutable::parse($pinned, config('cfb.timezone'))->startOfDay();
        }

        return Gameday::saturday();
    }
}
