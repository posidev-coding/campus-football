<?php

namespace App\Services\Espn\Sync;

use App\Models\Article;
use App\Services\Espn\EspnClient;
use Illuminate\Support\Facades\Cache;

/**
 * One article's body.
 *
 * The news list gives a headline, a thumbnail and a link out; the body lives
 * only at `now/{espnId}`, one request per article. That shape is the same trade
 * the game summary makes, so it is bounded the same two ways:
 *
 *   - A story is fetched ONCE, ever. Published prose does not change, so a
 *     stored body short-circuits every later view to a pure database read.
 *   - A miss is throttled by a per-ARTICLE cache lock, not per viewer. A
 *     hundred people opening the same story is one request.
 *
 * The lock is never released, only allowed to expire — it rate-limits, it does
 * not guard a critical section.
 *
 * What comes back is stored RAW. Rendering decisions (which tags survive, where
 * a photo placeholder resolves) belong to `ArticleStory` at read time, so
 * improving them does not mean re-fetching 200 articles.
 */
class SyncArticleStory
{
    /** One request per article per minute, however many people are reading. */
    private const LOCK_SECONDS = 60;

    public function __construct(private EspnClient $espn) {}

    /**
     * Fill in the body if it is missing and worth asking for.
     *
     * Returns whether the article now has one, so a caller can decide between
     * rendering the story and sending the reader to ESPN.
     */
    public function fill(Article $article): bool
    {
        if ($article->story !== null) {
            return true;
        }

        if (! $article->storyIsWorthFetching()) {
            return false;
        }

        $lock = Cache::lock("espn:story:{$article->espn_id}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        $payload = $this->espn->news($article->espn_id);

        // Never write a default when a feed returns nothing: a failed request
        // must not be recorded as "this article has no body", or a transient
        // 500 would permanently demote the article to a link.
        if ($payload === null) {
            return false;
        }

        $headline = $payload['headlines'][0] ?? [];
        $story = trim((string) ($headline['story'] ?? ''));

        $article->forceFill([
            // Empty stays NULL, and `story_fetched_at` is what distinguishes
            // "asked, and there is genuinely no body" from "never asked".
            'story' => $story === '' ? null : $story,
            'story_images' => $this->images($headline),
            'story_fetched_at' => now(),
        ])->save();

        return $article->story !== null;
    }

    /**
     * The images a story's `<photoN>` placeholders resolve against.
     *
     * Index 0 is the article's lead image and is rendered by the page itself;
     * `photo1` onwards line up with the rest. Verified across three articles
     * carrying between one and three placeholders.
     *
     * @param  array<string, mixed>  $headline
     * @return list<array{url: string, caption: ?string, credit: ?string, width: ?int, height: ?int}>
     */
    private function images(array $headline): array
    {
        return collect($headline['images'] ?? [])
            ->filter(fn ($image) => ! empty($image['url']))
            ->map(fn ($image) => [
                'url' => $image['url'],
                // ESPN puts a dimension suffix in `name` ("Dan Lanning
                // [608x342]"), so the caption is the readable one.
                'caption' => $image['caption'] ?? null,
                'credit' => $image['credit'] ?? null,
                'width' => isset($image['width']) ? (int) $image['width'] : null,
                'height' => isset($image['height']) ? (int) $image['height'] : null,
            ])
            ->values()
            ->all();
    }
}
