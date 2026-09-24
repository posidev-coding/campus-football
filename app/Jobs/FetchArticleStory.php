<?php

namespace App\Jobs;

use App\Jobs\Middleware\ThrottleEspn;
use App\Models\Article;
use App\Services\Espn\Sync\SyncArticleStory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One article's body, as one job.
 *
 * The article screen used to fetch this inline in mount(), which put ESPN's
 * `now` host in front of the render. When that host sat at its 5s ceiling,
 * twenty guest requests in a day waited out nearly all of it (CFB-96). The
 * screen now renders what is stored, and this fills the body in behind it.
 *
 * Unique on the ARTICLE, so a story shared into a group chat is one request
 * however many people open it at once. SyncArticleStory still keeps "fetched
 * once, ever" and its own per-article lock, so a second job for a filled
 * article returns before it touches the network.
 */
class FetchArticleStory implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Must stay below the queue's `retry_after` (90s) — see FetchGameSummary. */
    public int $timeout = 60;

    public int $tries = 3;

    /** The crash ceiling only — the lock releases on completion. */
    public int $uniqueFor = 300;

    public function __construct(public int $articleId) {}

    public function uniqueId(): string
    {
        return (string) $this->articleId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * Release rather than sleep when the ESPN allowance is spent.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new ThrottleEspn];
    }

    public function handle(SyncArticleStory $stories): void
    {
        $article = Article::find($this->articleId);

        // Gone, or answered since this was queued: several readers can queue
        // it before the first run lands, and fill() is where "already have
        // it" and "not worth asking" are both decided.
        if ($article === null) {
            return;
        }

        $stories->fill($article);
    }
}
