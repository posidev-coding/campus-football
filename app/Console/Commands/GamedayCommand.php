<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Enums\GamedayStatus;
use App\Models\GamedayWeek;
use App\Services\CfbCalendar;
use App\Services\GamedayFeed;
use App\Support\Gameday;
use App\Support\GamedayFallback;
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

        $this->trackRun('gameday', $year, function () use ($force, $feed, $resolver, $saturday, $year): int {
            $existing = GamedayWeek::query()
                ->where('season_year', $year)
                ->whereDate('saturday', $saturday->toDateString())
                ->first();

            /*
             * THE EARLY RETURN, MOVED RATHER THAN REMOVED. The whole point of
             * stopping here is that a resolved week costs no request, no parse
             * and no chance of a later run disagreeing with a good answer — so
             * this stays the first thing in the closure, above resolveWeek().
             * Recording a run is bookkeeping; a fix that fetched in order to
             * report that it did not need to fetch would be worse than the bug.
             *
             * What the row buys: the command runs five mornings a week and the
             * first success resolves the Saturday, so this is the path four of
             * them take. Without a row the schedule panel cannot tell "ran,
             * already resolved" from "never ran" and reads overdue for the rest
             * of the week, every week. Zero is a measured fact here, not a
             * substituted default.
             */
            if (! $force && $existing?->status->isKnown()) {
                $this->info("{$saturday->toDateString()} already resolved to {$existing->site} — nothing to do.");

                return 0;
            }

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
     * The feed first, the model only if it fails.
     *
     * Both paths land in the same place — a proposal our own venues and games
     * have already agreed with — so the row cannot tell you which one produced
     * it except by `confidence` and `source_url`. That is deliberate: the feed
     * is hand-maintained and demonstrably dirty, and being first-party is not
     * evidence.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function resolveWeek(
        GamedayFeed $feed,
        GamedayResolver $resolver,
        CarbonImmutable $saturday,
        ?GamedayWeek $existing,
    ): array {
        [$attributes, $reason] = $this->fromFeed($feed, $resolver, $saturday, $existing);

        if ($attributes !== null) {
            return [$attributes, $reason];
        }

        $this->line("  Feed: {$reason}.");

        [$proposal, $modelReason] = app(GamedayFallback::class)->attempt($saturday);

        if ($proposal === null) {
            return [null, $reason.'; model: '.mb_lcfirst($modelReason)];
        }

        $this->line('  Model resolved it instead.');

        return [[
            ...$proposal,
            'status' => GamedayStatus::Proposed,
            'announced_at' => $existing?->announced_at ?? now(),
        ], 'resolved by the model'];
    }

    /**
     * The primary path: four trusted fields, then our own data decides.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function fromFeed(
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
