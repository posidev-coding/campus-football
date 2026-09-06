<?php

namespace App\Actions;

use App\Enums\ActivityKind;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Support\Release;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Put one thing that happened onto the clickstream — a Redis stream on the
 * request path, MySQL hours later, and never the other way round.
 *
 * The transport is `XADD cfb:activity MAXLEN ~ 200000` on connection `pulse`
 * (Redis DB 2, out of `cache:clear`'s reach), which is the shape Pulse's own
 * ingest already uses here. A LIST would have been simpler and is wrong three
 * ways: no per-entry id to delete by, so the drain could not be idempotent; no
 * approximate trim, so a backed-up drain grows without bound; and no `XLEN`
 * for the monitor row that says how far behind it is. Pulse's OWN stream was
 * not reused because it unserializes with an `allowed_classes` list and lands
 * in `pulse_*` — a different vocabulary and a different retention.
 *
 * No consumer group. `XACK` bookkeeping buys nothing that
 * `activity_events.stream_id`'s unique index does not already guarantee for a
 * single consumer: the drain reads, inserts with `insertOrIgnore`, then
 * deletes, and a crash between the insert and the delete costs a duplicate
 * read rather than a duplicate row.
 *
 * EVERY FAILURE IS SWALLOWED on the write path, the `RecordUxEvent` rule. A
 * page view is never worth a 500 on the page — this measures the product, it
 * is not part of it. The drain is the opposite: it runs in the console under
 * `TracksFeedRun`, and a Redis failure there must reach the ledger.
 */
class RecordActivity
{
    /** The stream every sensor writes to. */
    public const STREAM = 'cfb:activity';

    /**
     * The approximate ceiling on unread entries.
     *
     * `MAXLEN ~` trims the OLDEST when the drain has fallen this far behind,
     * which is the accepted failure: at that point the pipeline has already
     * lost the day, and an unbounded stream would take Redis down with it.
     * Spelled out rather than derived, like every other window in this layer.
     */
    public const MAXLEN = 200_000;

    /** The pre-paint client cookie: `w{innerWidth}.s{0|1}`, no identifier. */
    public const COOKIE = 'cfb_client';

    /**
     * The ONE second dimension a page view may carry, and the routes it may
     * carry it on.
     *
     * The clubhouse is the only screen where the route name is not the whole
     * story: `?view=talk` and `?view=slate` are the difference between
     * "opened the group" and "read the talk", and `ActivityFeature::ReadTalk`
     * has no other source — a post is `conversation_posts` and a READ is
     * nowhere. Everything else about a query string is ignored on purpose: a
     * signed link, an invite code and a search term all travel there, and a
     * sensor that copied the query would be copying those into a table.
     *
     * `PageViewSensorTest` pins this list against the clubhouse's own `VIEWS`
     * so the two cannot drift apart.
     */
    public const FACET_ROUTES = ['pickem.group', 'pickem.room'];

    /** @var list<string> */
    public const FACETS = ['slate', 'standings', 'members', 'invite', 'talk'];

    /** The widest and narrowest client width worth believing. */
    private const MIN_WIDTH = 200;

    private const MAX_WIDTH = 8_000;

    /**
     * A rendered screen. Called by RecordPageView::terminate() and by nothing
     * else — every "is this a screen somebody read" rule lives in the
     * middleware, so this cannot be handed anything it has to second-guess.
     */
    public function pageView(Request $request): void
    {
        $this->push(ActivityKind::PageView, $request, self::facetFor($request));
    }

    /**
     * A named moment with no truth table. The facet is the emitter's, and it
     * is never read from the request.
     */
    public function action(ActivityKind $kind, Request $request, ?string $facet = null, ?Model $subject = null): void
    {
        $this->push($kind, $request, $facet, $subject);
    }

    /**
     * Entries buffered and not yet drained, or NULL when Redis cannot be
     * reached.
     *
     * Null rather than 0 because they mean opposite things: 0 is a drain
     * keeping up, and a monitor that printed 0 for an unreachable Redis would
     * report the healthiest possible number for the worst possible state.
     */
    public function pending(): ?int
    {
        try {
            return (int) Redis::connection('pulse')->xlen(self::STREAM);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Land the buffered entries in MySQL and drop them from the stream.
     *
     * `XRANGE` → `insertOrIgnore` → `XDEL`, in that order, because the delete
     * is what must never happen first: a crash after a read costs a re-read
     * that the unique `stream_id` collapses, while a crash after a delete
     * costs the entries themselves.
     *
     * `day` and `hour` are derived HERE, once, from `occurred_at` in the
     * league's timezone — the denormalized-index rule. The drain is also the
     * only writer of `users.last_seen_at`, batched into one statement per
     * drain rather than one per row.
     *
     * Throws. This runs in the console under `TracksFeedRun`, and a Redis
     * outage that the ledger did not record would read as a quiet day.
     *
     * @return int rows written
     */
    public function drain(int $max = 20_000): int
    {
        $redis = Redis::connection('pulse');

        $entries = (array) $redis->xRange(self::STREAM, '-', '+', $max);

        if ($entries === []) {
            return 0;
        }

        $rows = [];
        $seen = [];

        foreach ($entries as $id => $fields) {
            $row = self::row((string) $id, (array) $fields);

            if ($row === null) {
                continue;
            }

            $rows[] = $row;

            if ($row['user_id'] !== null) {
                // The LAST occurrence wins, and the entries arrive in stream
                // order, so this is the person's latest moment in the batch.
                $seen[$row['user_id']] = $row['occurred_at'];
            }
        }

        // Ignore, not upsert: a re-read of an entry that was already written
        // is the drain working, not a correction to apply.
        $written = $rows === [] ? 0 : ActivityEvent::query()->insertOrIgnore($rows);

        $redis->xDel(self::STREAM, array_map('strval', array_keys($entries)));

        self::touchLastSeen($seen);

        return $written;
    }

    /**
     * The flat XADD dictionary.
     *
     * Public so a test can hold it directly and assert what it can never
     * carry — the raw session id above all, which is the session COOKIE and
     * would turn a counting table into a hijacking kit.
     *
     * Every value is a scalar and every null travels as `''`: a Redis stream
     * field has no type, and the drain maps `''` back to NULL rather than to
     * 0 or false. That distinction is the whole of `viewport` and
     * `standalone` — "not reported" is a category, and writing false for it
     * would be claiming the reader was in a browser.
     *
     * @return array<string, string>
     */
    public static function fields(ActivityKind $kind, Request $request, ?string $facet, ?Model $subject): array
    {
        $user = $request->user();
        $standalone = self::standalone($request);

        return [
            'kind' => $kind->value,
            'user_id' => (string) ($user?->id ?? ''),
            // Guests only, and one-way: the first 32 hex of sha256(session
            // id), the shape RecordUxEvent::handleOnce already uses. It
            // counts PEOPLE inside a cell and dies with the session; it is
            // not a durable identifier and must never become one.
            'visitor' => $user === null ? self::visitor($request) : '',
            'audience' => (string) self::audience($user),
            'route' => (string) $request->route()?->getName(),
            'facet' => (string) $facet,
            // getMorphClass() answers with the enforced map's ALIAS, so the
            // column holds `group`, never a class name that a namespace move
            // would strand.
            'subject_type' => $subject === null ? '' : $subject->getMorphClass(),
            'subject_id' => (string) ($subject?->getKey() ?? ''),
            'occurred_at' => now()->utc()->format('Y-m-d H:i:s'),
            'viewport' => (string) (self::width($request) ?? ''),
            'standalone' => $standalone === null ? '' : ($standalone ? '1' : '0'),
            // Never null: the header is either there or it is not, and both
            // are facts about the request.
            'via_navigate' => $request->hasHeader('X-Livewire-Navigate') ? '1' : '0',
            'release' => (string) Release::version(),
        ];
    }

    /**
     * A guest's one-way handle: the first 32 hex of sha256(session id), the
     * shape `RecordUxEvent::handleOnce` already uses. It counts PEOPLE inside
     * a cell and dies with the session.
     *
     * Empty without a session — a console context, or a test driving a
     * component directly. `row()` then drops the entry rather than writing one
     * that belongs to nobody: exactly one of `user_id` and `visitor` is
     * non-null, and that is the sensor's job to hold.
     */
    private static function visitor(Request $request): string
    {
        return $request->hasSession()
            ? substr(hash('sha256', (string) $request->session()->getId()), 0, 32)
            : '';
    }

    /** 0 guest, 1 member, 2 staff — decided at request time, never at drain. */
    public static function audience(?User $user): int
    {
        return match (true) {
            $user === null => ActivityEvent::GUEST,
            $user->isAdmin() => ActivityEvent::STAFF,
            default => ActivityEvent::MEMBER,
        };
    }

    /**
     * The clubhouse's `?view=` stop, when this route is allowed one and the
     * value is one we render. Anything else is null — an allowlist, because
     * this is one of the two places client input enters the pipeline.
     */
    public static function facetFor(Request $request): ?string
    {
        if (! in_array((string) $request->route()?->getName(), self::FACET_ROUTES, true)) {
            return null;
        }

        $view = $request->query('view');

        return is_string($view) && in_array($view, self::FACETS, true) ? $view : null;
    }

    private function push(ActivityKind $kind, Request $request, ?string $facet, ?Model $subject = null): void
    {
        try {
            Redis::connection('pulse')->xAdd(
                self::STREAM,
                '*',
                self::fields($kind, $request, $facet, $subject),
                // Positional, and `true` is the APPROXIMATE trim: an exact
                // MAXLEN makes Redis walk the stream on every write, which is
                // a cost paid on the request path for a ceiling nobody reads
                // to the entry.
                self::MAXLEN,
                true,
            );
        } catch (Throwable $e) {
            Log::debug('Could not record an activity event.', ['kind' => $kind->value, 'error' => $e->getMessage()]);
        }
    }

    /**
     * One stream entry as an `activity_events` row, or null when it is not
     * one — an entry written by an older shape, or one whose kind has since
     * left the enum. The vocabulary is the code's, not Redis's.
     *
     * @param  array<string, string>  $fields
     * @return array<string, mixed>|null
     */
    private static function row(string $id, array $fields): ?array
    {
        $kind = ActivityKind::tryFrom((string) ($fields['kind'] ?? ''));
        $route = (string) ($fields['route'] ?? '');
        $occurred = (string) ($fields['occurred_at'] ?? '');

        $user = self::int($fields['user_id'] ?? '');
        $visitor = self::text($fields['visitor'] ?? '');

        /*
         * Exactly one of the two, always. An entry with neither belongs to
         * nobody and would make every "how many people" count wrong in a
         * direction nothing else would reveal; an entry with both would be
         * counted twice.
         */
        if ($kind === null || $route === '' || $occurred === '' || ($user === null) === ($visitor === null)) {
            return null;
        }

        // Derived ONCE, here, and never edited afterwards: the league day, not
        // the UTC one, because a Saturday-night screen at 01:00 UTC Sunday
        // belongs to Saturday. SQL cannot be asked for it — CONVERT_TZ does
        // not know about DST the way the app does.
        $league = CarbonImmutable::parse($occurred, 'UTC')->setTimezone(config('cfb.timezone'));

        return [
            'stream_id' => $id,
            'kind' => $kind->value,
            'user_id' => $user,
            'visitor' => $visitor,
            'audience' => (int) ($fields['audience'] ?? ActivityEvent::GUEST),
            'route' => $route,
            'facet' => self::text($fields['facet'] ?? ''),
            'subject_type' => self::text($fields['subject_type'] ?? ''),
            'subject_id' => self::int($fields['subject_id'] ?? ''),
            'occurred_at' => $occurred,
            'day' => $league->format('Y-m-d'),
            'hour' => (int) $league->format('G'),
            'viewport' => self::int($fields['viewport'] ?? ''),
            // `''` is "not reported" and stays null. Never false: false is a
            // claim, and we do not have one to make.
            'standalone' => ($fields['standalone'] ?? '') === '' ? null : (bool) $fields['standalone'],
            'via_navigate' => (bool) ($fields['via_navigate'] ?? false),
            'release' => self::text($fields['release'] ?? ''),
        ];
    }

    /**
     * The drain's own write: when each person was last seen, batched.
     *
     * @param  array<int, string>  $seen  user id => UTC timestamp
     */
    private static function touchLastSeen(array $seen): void
    {
        if ($seen === []) {
            return;
        }

        foreach (array_chunk($seen, 500, preserve_keys: true) as $chunk) {
            $case = '';
            $bindings = [];

            foreach ($chunk as $id => $at) {
                $case .= ' when ? then ?';
                $bindings[] = $id;
                $bindings[] = $at;
            }

            $ids = array_keys($chunk);

            /*
             * GREATEST keeps the column monotonic: a drain that re-reads an
             * entry it already wrote (the crash window the unique stream_id
             * covers) must not walk somebody's last-seen backwards. The
             * COALESCE is needed because GREATEST(NULL, x) is NULL in MySQL,
             * which would blank the column instead of raising it.
             */
            DB::statement(
                'update users set last_seen_at = greatest(coalesce(last_seen_at, \'1970-01-01 00:00:00\'), case id'.$case.' end)'
                .' where id in ('.implode(',', array_fill(0, count($ids), '?')).')',
                [...$bindings, ...$ids],
            );
        }
    }

    /**
     * The client cookie's width, or null.
     *
     * A strict regex and a sane range, because this is the other place client
     * input enters. An out-of-range width is NULL — "not reported" — never
     * clamped: a clamp would invent a bucket boundary out of a value we know
     * to be a lie.
     */
    private static function width(Request $request): ?int
    {
        if (! preg_match('/^w(\d{1,5})\.s[01]$/', (string) $request->cookie(self::COOKIE), $m)) {
            return null;
        }

        $width = (int) $m[1];

        return $width >= self::MIN_WIDTH && $width <= self::MAX_WIDTH ? $width : null;
    }

    /** Installed or not, or null when the cookie has not been written yet. */
    private static function standalone(Request $request): ?bool
    {
        return preg_match('/^w\d{1,5}\.s([01])$/', (string) $request->cookie(self::COOKIE), $m) === 1
            ? $m[1] === '1'
            : null;
    }

    private static function text(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function int(string $value): ?int
    {
        return $value === '' ? null : (int) $value;
    }
}
