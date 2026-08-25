<?php

namespace App\Support;

use App\Actions\RecordAiSpend;
use App\Ai\Agents\GamedaySite;
use App\Enums\AiModel;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The model path, wrapped in the guards that are the actual feature.
 *
 * It runs only when the feed has already failed, and everything it produces
 * goes through the SAME resolver and the same checks the feed's output does.
 * Being a model buys it no extra suspicion and being first-party bought the
 * feed no extra trust — both propose a place, and our own venues and games
 * decide whether it is real.
 *
 * Every rejection here returns null, which the caller records as `unknown`.
 * That is the point: on a feature whose whole job is producing a location,
 * "we do not know" has to be a cheaper outcome than a guess.
 */
class GamedayFallback
{
    /**
     * A resolved, guarded proposal — or null, meaning we still do not know.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    public function attempt(CarbonImmutable $saturday): array
    {
        $budget = app(AiBudget::class);

        /*
         * ONE question, not two. AiBudget::allows() answers the master switch
         * and the ceiling together precisely so a caller cannot check the
         * money and forget the switch. Asked BEFORE the call, while there is
         * still a deterministic "not yet announced" to serve instead of an
         * error.
         */
        if (! $budget->allows()) {
            return [null, $budget->refusal() ?? 'The AI layer declined the call'];
        }

        try {
            $response = (new GamedaySite)->prompt(
                "Where is ESPN's College Football GameDay broadcasting from on Saturday, "
                .$saturday->format('F j, Y').'?'
            );
        } catch (Throwable $e) {
            return [null, 'The model call failed: '.mb_substr($e->getMessage(), 0, 120)];
        }

        $this->recordSpend($response);

        return $this->guard($response, $saturday);
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function guard(mixed $response, CarbonImmutable $saturday): array
    {
        // 5. Unknown is a first-class answer, and the model is told to use it.
        if (($response['announced'] ?? false) !== true) {
            return [null, 'The model found no announcement for that Saturday'];
        }

        // 1. Search is mandatory; parametric memory is not a source. An answer
        //    with nothing behind it is discarded unread.
        $source = trim((string) ($response['source_url'] ?? ''));

        if ($source === '' || ! str_starts_with($source, 'http')) {
            return [null, 'The model answered without citing a source'];
        }

        $city = trim((string) ($response['city'] ?? ''));
        $state = mb_strtoupper(trim((string) ($response['state'] ?? '')));

        if ($city === '' || $state === '') {
            return [null, 'The model named no city and state'];
        }

        $resolver = app(GamedayResolver::class);

        /*
         * 3. THE CONTRADICTION CHECK, and the strongest guard we have because
         *    it is deterministic and free. GameDay broadcasts from a campus
         *    hosting a game; if nothing in our own schedule is played there
         *    that Saturday, the answer contradicts the database and loses.
         */
        $game = $resolver->resolve($city, $state, $saturday);

        if ($game === null) {
            return [null, "The model's \"{$city}, {$state}\" matches no single game we hold that Saturday"];
        }

        $host = $resolver->hostTeam($game);

        /*
         * 2. The named school must be one we already hold, and must be the
         *    same one our data says is hosting. A right city with the wrong
         *    school is the shape a plausible hallucination takes.
         */
        $named = trim((string) ($response['host_team_name'] ?? ''));

        if ($named !== '' && $host !== null) {
            $match = Search::teams($named, limit: 1)->first();

            if ($match === null || $match->id !== $host->id) {
                return [null, "The model named {$named}, but that Saturday's game there is hosted by {$host->display_name}"];
            }
        }

        return [[
            'site' => $game->venue?->name,
            'city' => $city,
            'state' => $state,
            'team_id' => $host?->id,
            'game_id' => $game->id,
            'source_url' => $source,
            'confidence' => (float) ($response['confidence'] ?? 0),
        ], 'resolved'];
    }

    /**
     * Charged whatever the guards then decide, because the tokens were spent
     * either way — a budget that only counts the calls it liked is a budget
     * that undercounts exactly when something is going wrong.
     */
    private function recordSpend(mixed $response): void
    {
        try {
            app(RecordAiSpend::class)->handle(
                AiModel::Sonnet5,
                'gameday',
                $response->usage->promptTokens,
                $response->usage->completionTokens,
                $response->usage->cacheWriteInputTokens,
                $response->usage->cacheReadInputTokens,
            );
        } catch (Throwable) {
            // Bookkeeping never breaks the product — the same rule the
            // telemetry writers follow.
        }
    }
}
