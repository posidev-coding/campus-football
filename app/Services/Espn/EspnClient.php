<?php

namespace App\Services\Espn;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The single place this application talks to ESPN.
 *
 * v3 made 20 bare `Http::get()` calls scattered across jobs, controllers, and
 * Livewire render methods. None set a timeout, none retried, and not one
 * checked the response status before calling `->json()` — so a 429 or a 500
 * produced null, and the caller array-indexed into it. Three of those call
 * sites were on the request path, multiplying with viewer count.
 *
 * Everything here funnels through one method so timeouts, retries, throttling,
 * caching, and failure semantics are decided once.
 */
class EspnClient
{
    /** Requests issued by this instance — reported into feed_runs. */
    protected int $callCount = 0;

    public function callCount(): int
    {
        return $this->callCount;
    }

    public function resetCallCount(): void
    {
        $this->callCount = 0;
    }

    public function core(string $path, array $query = [], ?int $ttl = null): ?array
    {
        return $this->get($this->url('core', $path), $query, $ttl);
    }

    public function site(string $path, array $query = [], ?int $ttl = null): ?array
    {
        return $this->get($this->url('site', $path), $query, $ttl);
    }

    public function web(string $path, array $query = [], ?int $ttl = null): ?array
    {
        return $this->get($this->url('web', $path), $query, $ttl);
    }

    /**
     * One article's full body, from ESPN's league-agnostic news host.
     *
     * Keyed on the article id alone — there is no college-football path here,
     * and no way to ask the news LIST for bodies, so this is one request per
     * article. Cheap (6-53 KB, measured across 18) but never free, which is why
     * the caller stores what comes back rather than fetching per view.
     */
    public function news(int $espnId, ?int $ttl = null): ?array
    {
        return $this->get($this->url('now', (string) $espnId), [], $ttl);
    }

    /**
     * Follow a `$ref` from a payload.
     *
     * ESPN's core API returns collections as lists of `$ref` URLs, and mixes
     * http:// and https:// within the same response. Normalising the scheme
     * here means callers never accidentally downgrade to plaintext.
     */
    public function ref(string $ref, ?int $ttl = null): ?array
    {
        return $this->get(str_replace('http://', 'https://', $ref), [], $ttl);
    }

    /**
     * The largest page ESPN actually honours.
     *
     * Verified live on the recruiting collection: `limit=1000` returns 1000,
     * and `limit=2000` is SILENTLY IGNORED — you get the default 25 with no
     * error and a pageCount to match. So an un-clamped caller asking for
     * everything gets the first page instead, which is exactly how the
     * recruiting table ended up holding 25 of 5,193 prospects.
     */
    public const MAX_PAGE_SIZE = 1000;

    /**
     * Walk a paginated core-API collection, yielding each item's resolved body.
     *
     * Yields rather than returns: a full-season event list is ~918 items and a
     * roster sweep is tens of thousands, and holding all of that in memory to
     * return one array is how a sync job gets OOM-killed halfway through.
     *
     * `$inline` is the cost lever. Some collections return items that carry
     * BOTH a `$ref` and the whole document — diffed on recruiting and the key
     * sets are identical, nothing is missing — so following the ref buys
     * nothing and costs one request per item. A full recruiting class went from
     * ~5,200 requests to 6 on that alone.
     *
     * It is opt-in rather than sniffed from the payload: a collection of bare
     * refs looks similar enough that guessing would eventually starve one of
     * its documents, and that failure is silent.
     */
    public function paginate(
        string $path,
        array $query = [],
        ?int $ttl = null,
        int $perPage = 100,
        bool $inline = false,
    ): Generator {
        $page = 1;
        $perPage = min($perPage, self::MAX_PAGE_SIZE);

        do {
            // Merged LAST so these win: a caller passing its own `limit` would
            // otherwise fight the pagination it is asking for.
            $body = $this->core($path, array_merge($query, ['limit' => $perPage, 'page' => $page]), $ttl);

            if ($body === null || empty($body['items'])) {
                return;
            }

            foreach ($body['items'] as $item) {
                // A collection page is either a list of $refs or of inline objects.
                yield ! $inline && isset($item['$ref']) ? $this->ref($item['$ref'], $ttl) : $item;
            }

            $pageCount = (int) ($body['pageCount'] ?? 1);
            $page++;
        } while ($page <= $pageCount);
    }

    /**
     * Returns the decoded body, or null when the resource legitimately does not
     * exist or could not be fetched.
     *
     * Null is a real answer here, not an error swallow: ESPN 404s constantly
     * for perfectly valid requests (a freshman with no stats, an offseason
     * injuries list, a predictor for a game that has not been modelled yet).
     * Callers must treat null as "no data" and MUST NOT write defaults on it —
     * that is precisely how v3 overwrote 9-1 teams with 0-0.
     */
    public function get(string $url, array $query = [], ?int $ttl = null): ?array
    {
        $ttl ??= config('espn.cache.reference');

        if ($ttl > 0) {
            $key = 'espn:'.sha1($url.'?'.http_build_query($query));

            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached === 'NULL' ? null : $cached;
            }

            $body = $this->fetch($url, $query);

            // Cache misses too, briefly — an endpoint that 404s for one athlete
            // will 404 for every job in this run, and re-asking is pure waste.
            Cache::put($key, $body ?? 'NULL', $body === null ? min($ttl, 300) : $ttl);

            return $body;
        }

        return $this->fetch($url, $query);
    }

    protected function fetch(string $url, array $query = []): ?array
    {
        $this->throttle();
        $this->callCount++;

        try {
            $response = $this->request()->get($url, $query);
        } catch (\Throwable $e) {
            // Connection failures and exhausted retries land here.
            Log::warning('ESPN request failed', [
                'url' => $url,
                'error' => Str::limit($e->getMessage(), 200),
            ]);

            return null;
        }

        return $this->decode($response, $url);
    }

    protected function decode(Response $response, string $url): ?array
    {
        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            Log::warning('ESPN returned an unsuccessful response', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = $response->json();

        // A 200 carrying a non-array body means we asked for something that
        // does not return JSON, or ESPN served an error page. Either way the
        // caller cannot use it.
        if (! is_array($body)) {
            Log::warning('ESPN returned a non-array body', [
                'url' => $url,
                'type' => get_debug_type($body),
            ]);

            return null;
        }

        return $body;
    }

    protected function request(): PendingRequest
    {
        $http = config('espn.http');

        return Http::withHeaders(['User-Agent' => $http['user_agent']])
            ->timeout($http['timeout'])
            ->connectTimeout($http['connect_timeout'])
            ->retry(
                times: $http['retries'],
                sleepMilliseconds: fn (int $attempt) => $http['retry_delay_ms'] * (2 ** ($attempt - 1)),
                when: fn (\Throwable $e) => $this->shouldRetry($e),
                throw: false,
            );
    }

    /**
     * Retry transport failures, 429s, and 5xx. Never retry a 4xx — the request
     * is wrong and repeating it just burns the rate limit.
     */
    protected function shouldRetry(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    /**
     * Request throttle, shared across every process using the same cache.
     *
     * Not merely process-wide: RateLimiter is cache-backed, so ten queue
     * workers pulling game summaries in parallel still sit under one 240/min
     * ceiling. That is precisely what makes fanning a backfill out across
     * workers safe — throughput rises, upstream load does not.
     *
     * Blocks rather than failing: a sync job would rather take longer than lose
     * a run, and the backfill deliberately runs at the edge of this limit.
     */
    protected function throttle(): void
    {
        $limit = (int) config('espn.http.rate_limit');

        if ($limit <= 0) {
            return;
        }

        while (RateLimiter::tooManyAttempts('espn-api', $limit)) {
            usleep(250_000);
        }

        RateLimiter::hit('espn-api', 60);
    }

    protected function url(string $host, string $path): string
    {
        return rtrim(config("espn.hosts.{$host}"), '/').'/'.ltrim($path, '/');
    }
}
