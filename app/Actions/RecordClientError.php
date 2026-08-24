<?php

namespace App\Actions;

use App\Models\ClientError;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Record a JavaScript error reported by a browser — deduped in Redis before
 * anything reaches MySQL.
 *
 * The shape of the problem: one bad deploy is thousands of identical
 * `window.onerror` posts inside a minute, and a row apiece would be a
 * self-inflicted write storm over a single fact. So a fingerprint buys at most
 * ONE row per window, and the row carries how many reports that window
 * actually saw — because "this fired 4,000 times" and "this fired once" are
 * different bugs and the row is useless if it cannot tell them apart.
 *
 * The counter is refreshed to MySQL only at powers of ten, which bounds the
 * writes at four per fingerprint per window however loud the loop gets.
 *
 * An action rather than controller code: the endpoint is one caller, and the
 * dedupe is the part worth testing without an HTTP request around it.
 */
class RecordClientError
{
    /**
     * How long one fingerprint stays deduped.
     *
     * Spelled out rather than derived. `now()->addMinutes(5)->diffInSeconds()`
     * is NEGATIVE in Carbon 3, which expires the key the instant it is written
     * and turns the dedupe into a passthrough — a guard that fails OPEN.
     */
    public const WINDOW_SECONDS = 300;

    /** Report counts that earn a second write inside one window. */
    private const MILESTONES = [10, 100, 1_000, 10_000];

    /**
     * @param  array{kind: string, message: string, source: ?string, line: ?int, col: ?int, stack: ?string, path: ?string, user_agent: ?string, viewport: ?int, standalone: bool}  $report
     * @return ClientError|null the row, when this report opened a window
     */
    public function handle(array $report, ?User $user = null): ?ClientError
    {
        $fingerprint = self::fingerprint($report);
        $key = "client-error:{$fingerprint}";

        // add() is set-if-absent WITH a ttl, so the window starts on the first
        // report and the later incrby inherits its expiry.
        if (Cache::add($key, 1, self::WINDOW_SECONDS)) {
            $error = ClientError::create([...$report, 'fingerprint' => $fingerprint, 'user_id' => $user?->id]);

            // Plain int, and nothing else ever goes in here. A model or a
            // Carbon comes back out of Redis as __PHP_Incomplete_Class on the
            // SECOND request, never the first.
            Cache::put("{$key}:row", $error->id, self::WINDOW_SECONDS);

            return $error;
        }

        $count = (int) Cache::increment($key);

        if (in_array($count, self::MILESTONES, true) && ($id = Cache::get("{$key}:row"))) {
            ClientError::whereKey($id)->update(['reports' => $count]);
        }

        return null;
    }

    /**
     * What makes two reports the same bug.
     *
     * Digits in the message are normalized, so "game 4210 not found" and
     * "game 91 not found" are one entry rather than one per game. The build
     * hash in the script name is collapsed for the same reason in the other
     * direction: without it, every deploy renames the file and an unfixed bug
     * reappears as a brand new one.
     *
     * @param  array{kind: string, message: string, source: ?string, line: ?int, col: ?int}  $report
     */
    public static function fingerprint(array $report): string
    {
        return sha1(implode('|', [
            $report['kind'],
            preg_replace('/\d+/', 'N', mb_substr($report['message'], 0, 200)),
            self::normalizeSource($report['source'] ?? ''),
            $report['line'] ?? '',
            $report['col'] ?? '',
        ]));
    }

    /** Host off, content hash collapsed — `/build/assets/app-*.js`. */
    private static function normalizeSource(string $source): string
    {
        $path = parse_url($source, PHP_URL_PATH) ?: $source;

        return (string) preg_replace('/-[A-Za-z0-9_-]{8,}(\.\w+)$/', '-*$1', $path);
    }
}
