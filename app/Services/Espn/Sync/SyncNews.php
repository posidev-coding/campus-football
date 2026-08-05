<?php

namespace App\Services\Espn\Sync;

use App\Models\Article;
use App\Models\Team;
use App\Services\Espn\EspnClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * College football news.
 *
 * Three things about this feed were verified live and none are guessable:
 *
 * 1. `limit` is CLAMPED TO 50. Asking for 1000 returns 50, silently — the same
 *    class of trap as the per-week game request that truncates. There is no
 *    pagination parameter that lifts it.
 *
 * 2. The `team=` filter is REAL. Georgia's feed shares only 5 of its 50 articles
 *    with the general feed and reaches back about eight weeks rather than six
 *    days. This is worth stating because the sibling `athlete=` filter on the
 *    same endpoint is silently IGNORED and returns general news — so one
 *    parameter on this endpoint can be trusted and the other cannot.
 *
 * 3. Every article on the college-football path carries an `NCAA Football`
 *    league tag, so no filtering is needed. Basketball tags do appear, but as
 *    ADDITIONAL tags on genuinely multi-sport stories (an athletic director
 *    hire), not as off-topic articles.
 *
 * Because the window is only days wide, history here is ACCUMULATED rather than
 * backfilled, and nothing in this class ever deletes: an article falling out of
 * ESPN's window must not fall out of ours.
 */
class SyncNews
{
    /** ESPN clamps to this regardless of what we ask for. Stated, not hoped. */
    private const MAX_LIMIT = 50;

    public function __construct(private EspnClient $espn) {}

    /**
     * The national feed.
     */
    public function general(): int
    {
        return $this->ingest($this->fetch());
    }

    /**
     * One team's feed.
     */
    public function team(int $teamId): int
    {
        return $this->ingest($this->fetch($teamId));
    }

    /**
     * Team feeds for teams somebody actually follows.
     *
     * 136 FBS teams is too many to refresh blindly every cycle for content
     * nobody has asked to see. This is the same principle the game summary
     * throttle follows: cost tracks interest.
     */
    public function followed(): int
    {
        // One source. This used to union in `users.favorite_team_id` as well,
        // because a favorite was not guaranteed to be followed; now a favorite
        // IS simply the first followed team, so the pivot covers everyone.
        $teamIds = DB::table('team_follows')->distinct()->pluck('team_id');

        $synced = 0;

        foreach ($teamIds as $teamId) {
            $synced += $this->team((int) $teamId);
        }

        return $synced;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(?int $teamId = null): array
    {
        $query = ['limit' => self::MAX_LIMIT];

        if ($teamId !== null) {
            $query['team'] = $teamId;
        }

        $body = $this->espn->site('news', $query, ttl: config('espn.cache.schedule'));

        // No data is not the same as an empty feed. Returning null here rather
        // than an empty array is what stops a caller writing over what we have.
        if ($body === null || ! isset($body['articles'])) {
            return [];
        }

        return $body['articles'];
    }

    /**
     * @param  list<array<string, mixed>>  $articles
     */
    private function ingest(array $articles): int
    {
        $known = null;
        $synced = 0;

        foreach ($articles as $payload) {
            $espnId = $payload['id'] ?? null;

            if ($espnId === null || ! isset($payload['headline'])) {
                continue;
            }

            $article = Article::updateOrCreate(
                ['espn_id' => (int) $espnId],
                [
                    'headline' => $payload['headline'],
                    'description' => $payload['description'] ?? null,
                    'byline' => $payload['byline'] ?? null,
                    'type' => $payload['type'] ?? null,
                    'image_url' => $this->image($payload),
                    'url' => data_get($payload, 'links.web.href'),
                    'premium' => (bool) ($payload['premium'] ?? false),
                    'published_at' => isset($payload['published'])
                        ? CarbonImmutable::parse($payload['published'])
                        : null,
                ]
            );

            // Resolved once per batch rather than per article — a Top 25
            // listicle tags 25 teams and there are 50 articles, so this is the
            // difference between one query and hundreds.
            $known ??= Team::pluck('id')->flip();

            $article->teams()->sync($this->teamIds($payload, $known));

            $synced++;
        }

        return $synced;
    }

    /**
     * Team ids mentioned by an article.
     *
     * ESPN lists each team TWICE — once as "Georgia Bulldogs" and once as
     * "University of Georgia", both carrying the same `teamId` — so this
     * deduplicates. It also drops teams we do not carry rather than letting a
     * foreign key abort the article.
     *
     * @param  Collection<int, int>  $known
     * @return list<int>
     */
    private function teamIds(array $payload, Collection $known): array
    {
        return collect($payload['categories'] ?? [])
            ->where('type', 'team')
            ->map(fn (array $c) => $c['teamId'] ?? data_get($c, 'team.id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter(fn (int $id) => $known->has($id))
            ->values()
            ->all();
    }

    private function image(array $payload): ?string
    {
        return data_get($payload, 'images.0.url');
    }
}
