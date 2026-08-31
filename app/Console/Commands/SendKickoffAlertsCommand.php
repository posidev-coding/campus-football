<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Models\Game;
use App\Models\Team;
use App\Models\User;
use App\Notifications\KickoffAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Push a kickoff alert to everyone following either team of a game that
 * starts inside the next fifteen minutes.
 *
 * The sweep runs every five minutes across that window, so the per-game
 * `kickoff_alert_sent_at` stamp is what keeps a game from alerting three
 * times — and a game is stamped even when nobody subscribed follows it,
 * because "checked, nothing to send" must not become "retry forever".
 * Recipients ride the `team_follows.team_id` index, which exists precisely
 * so "who follows this team" is never a table scan — and they are fetched
 * ONCE for the whole window, then matched per game in PHP: the noon slate
 * puts ~15 games in one sweep, and a users query per game was 15 scans
 * with two EXISTS subqueries each, every five minutes.
 */
class SendKickoffAlertsCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:kickoff-alerts
                            {--dry : Report what would be sent and send nothing}';

    protected $description = 'Push kickoff alerts for games starting inside the next fifteen minutes';

    public function handle(): int
    {
        // A preview is not the scheduled run, so it stays off the ledger.
        if ($this->option('dry')) {
            $games = $this->window();

            if ($games->isEmpty()) {
                $this->info('Nothing kicks off inside the window.');

                return self::SUCCESS;
            }

            $recipients = $this->recipients($games);

            $this->table(['game', 'kickoff', 'reachable followers'], $games->map(fn (Game $game) => [
                $game->name, $game->kickoff_at->toDateTimeString(),
                $recipients->filter(fn (User $user) => $this->favoriteSide($user, $game) !== null)->count(),
            ]));

            return self::SUCCESS;
        }

        $alerted = 0;

        $sent = $this->trackRun('kickoff-alerts', null, function () use (&$alerted): int {
            /*
             * A tick with nothing kicking off is still a run, and here it is
             * the COMMON tick: the sweep fires every five minutes across the
             * whole live window and only the handful inside a kickoff have
             * anything to send. The completed row with a zero count is what
             * lets the schedule panel tell "ran, nothing to do" from "never
             * ran" -- zero is a measured fact, not a substituted default.
             */
            $games = $this->window();

            if ($games->isEmpty()) {
                return 0;
            }

            $alerted = $games->count();
            $recipients = $this->recipients($games);
            $total = 0;

            foreach ($games as $game) {
                foreach ($recipients as $user) {
                    $team = $this->favoriteSide($user, $game);

                    if ($team === null) {
                        continue;
                    }

                    $user->notify(new KickoffAlertNotification($game, $team->placeName()));

                    $total++;
                }

                $game->forceFill(['kickoff_alert_sent_at' => now()])->save();
            }

            return $total;
        });

        if ($alerted === 0) {
            $this->info('Nothing kicks off inside the window.');

            return self::SUCCESS;
        }

        $this->info("Alerted {$sent} ".str('follower')->plural($sent).' across '.$alerted.' '.str('game')->plural($alerted).'.');

        return self::SUCCESS;
    }

    /**
     * The games this tick is answering for: kicking off inside the next
     * fifteen minutes and not yet stamped.
     *
     * @return Collection<int, Game>
     */
    private function window()
    {
        return Game::query()
            ->startingSoon()
            ->whereNull('kickoff_alert_sent_at')
            ->get();
    }

    /**
     * Everyone with a push subscription following either side of ANY game
     * in the window — one query, matched per game in PHP. The constrained
     * eager load carries the route key and every column placeName() reads —
     * a thinner select renders the wrong name silently.
     *
     * @param  Collection<int, Game>  $games
     * @return Collection<int, User>
     */
    private function recipients(Collection $games)
    {
        $teamIds = $games
            ->flatMap(fn (Game $game) => [$game->home_team_id, $game->away_team_id])
            ->unique()
            ->values()
            ->all();

        return User::query()
            ->whereHas('pushSubscriptions')
            ->whereHas('followedTeams', fn (Builder $query) => $query->whereIn('teams.id', $teamIds))
            ->with(['followedTeams' => fn ($query) => $query
                ->whereIn('teams.id', $teamIds)
                ->select('teams.id', 'teams.slug', 'teams.location', 'teams.display_name')])
            ->get();
    }

    /**
     * The reader's favorite of this game's two sides, or null when they
     * follow neither. The relation orders by pivot position, so the first
     * hit is the one whose name belongs in the body — the same team the
     * old per-game query put first.
     */
    private function favoriteSide(User $user, Game $game): ?Team
    {
        return $user->followedTeams->first(
            fn ($team) => in_array($team->id, [$game->home_team_id, $game->away_team_id], true),
        );
    }
}
