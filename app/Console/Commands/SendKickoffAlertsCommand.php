<?php

namespace App\Console\Commands;

use App\Console\Concerns\TracksFeedRun;
use App\Models\Game;
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
 * so "who follows this team" is never a table scan.
 */
class SendKickoffAlertsCommand extends Command
{
    use TracksFeedRun;

    protected $signature = 'cfb:kickoff-alerts
                            {--dry : Report what would be sent and send nothing}';

    protected $description = 'Push kickoff alerts for games starting inside the next fifteen minutes';

    public function handle(): int
    {
        $games = Game::query()
            ->startingSoon()
            ->whereNull('kickoff_alert_sent_at')
            ->get();

        if ($games->isEmpty()) {
            $this->info('Nothing kicks off inside the window.');

            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $this->table(['game', 'kickoff', 'reachable followers'], $games->map(fn (Game $game) => [
                $game->name, $game->kickoff_at->toDateTimeString(), $this->recipients($game)->count(),
            ]));

            return self::SUCCESS;
        }

        $sent = $this->trackRun('kickoff-alerts', null, function () use ($games) {
            $total = 0;

            foreach ($games as $game) {
                foreach ($this->recipients($game) as $user) {
                    // Their favorite of the matchup's teams — the relation
                    // orders by pivot position, so first() is the one whose
                    // name belongs in the body.
                    $team = $user->followedTeams->first();

                    $user->notify(new KickoffAlertNotification($game, $team->placeName()));

                    $total++;
                }

                $game->forceFill(['kickoff_alert_sent_at' => now()])->save();
            }

            return $total;
        });

        $this->info("Alerted {$sent} ".str('follower')->plural($sent).' across '.$games->count().' '.str('game')->plural($games->count()).'.');

        return self::SUCCESS;
    }

    /**
     * Everyone with a push subscription following either side. The
     * constrained eager load carries the route key and every column
     * placeName() reads — a thinner select renders the wrong name silently.
     *
     * @return Collection<int, User>
     */
    private function recipients(Game $game)
    {
        $teamIds = [$game->home_team_id, $game->away_team_id];

        return User::query()
            ->whereHas('pushSubscriptions')
            ->whereHas('followedTeams', fn (Builder $query) => $query->whereIn('teams.id', $teamIds))
            ->with(['followedTeams' => fn ($query) => $query
                ->whereIn('teams.id', $teamIds)
                ->select('teams.id', 'teams.slug', 'teams.location', 'teams.display_name')])
            ->get();
    }
}
