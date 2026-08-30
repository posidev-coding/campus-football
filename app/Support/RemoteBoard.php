<?php

namespace App\Support;

use App\Http\Middleware\EnsureOpsToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The board, over HTTP — what `cfb:issue` and `cfb:issues` talk to when a board
 * URL is configured.
 *
 * {@see IssueBoard} reads the table this process is connected to. That table is
 * not the board anybody looks at: cards are filed against a deployment by the
 * advisor, so a session working in a checkout could show a card, write its plan
 * on the trail and hand the card to review, have all three succeed, and have
 * none of them reach the board a human reads. This is the other end of the same
 * pair — same verbs, same arrays, a different board.
 *
 * **There is no fallback, and that is the feature.** Every method here returns
 * null and sets {@see self::$refusal} when the board does not answer. It never
 * reaches for the local table, never retries a write, and never queues one:
 * writing to a second board that also looks authoritative is the bug this class
 * exists to remove, not a graceful degradation of it. A caller turns a refusal
 * into a non-zero exit.
 *
 * **The token is a header and only ever a header.** It is never in a URL, never
 * in an argument, never in a message — a token in argv is a token in shell
 * history and in `ps`, and a token in an exception message is a token in a log.
 * The one place a message is built from something we did not write (a transport
 * failure) is scrubbed on the way past.
 */
class RemoteBoard
{
    /** Where the board serves its issue routes, on the other side. */
    private const PATH = '/ops/issues';

    /**
     * The same vocabulary the board's router constrains `{issue}` to, checked
     * here so a mistyped handle is a sentence rather than a request that
     * composes a path somebody has to read a 404 to understand.
     */
    private const HANDLE = '/^[A-Za-z0-9][A-Za-z0-9-]{0,120}$/';

    public const TIMEOUT_SECONDS = 10;

    public const CONNECT_TIMEOUT_SECONDS = 5;

    /** Null means the call was refused or never landed; the message says which. */
    public ?string $refusal = null;

    private readonly string $origin;

    private readonly string $token;

    public function __construct()
    {
        $this->origin = self::origin();
        $this->token = (string) config('cfb.ops_token');
    }

    /**
     * Whether this process works a board over HTTP at all.
     *
     * Configuration, never a guess: no URL means the local table, which is the
     * behavior these commands have always had, and nothing infers a remote
     * board from a hostname or a branch name.
     */
    public static function configured(): bool
    {
        return self::origin() !== '';
    }

    /**
     * The configured origin, trimmed of a trailing slash so composing a path
     * onto it cannot produce a double one — a board that answers `//ops/...`
     * with a redirect would turn a POST into a GET and lose the body.
     */
    private static function origin(): string
    {
        return rtrim(trim((string) config('cfb.board_url')), '/');
    }

    /**
     * WHICH board, said out loud — {@see IssueBoard::whereItLooked()}'s opposite
     * number, and used in exactly the same places for the same reason. A
     * refusal that does not name the board reads as a card that does not exist.
     */
    public function whereItLooked(): string
    {
        return $this->origin;
    }

    /**
     * The ready queue.
     *
     * The one list the board serves to a client that composes its own URL, so
     * it is the one list `cfb:issues` can ask for remotely.
     *
     * @return list<array<string, mixed>>|null
     */
    public function ready(int $limit): ?array
    {
        $this->refusal = null;

        $body = $this->call('get', '/ready', ['limit' => $limit], null);

        return $body === null ? null : array_values((array) ($body['issues'] ?? []));
    }

    /**
     * One issue, whole — what a session works from.
     *
     * @return array<string, mixed>|null
     */
    public function brief(string $handle): ?array
    {
        return $this->forIssue('get', $handle, '/brief');
    }

    /**
     * Take the claim and mint the branch.
     *
     * The atomicity is the BOARD'S: its route runs one conditional update and
     * answers 409 when somebody else holds the card. Nothing here reads the
     * claim first and writes second — that shape would put the race back in,
     * over a network, which is the one place it would actually be lost.
     *
     * @return array<string, mixed>|null
     */
    public function start(string $handle, string $as): ?array
    {
        return $this->forIssue('post', $handle, '/start', ['as' => $as]);
    }

    /** @return array<string, mixed>|null */
    public function claim(string $handle, string $as): ?array
    {
        return $this->forIssue('post', $handle, '/claim', ['as' => $as]);
    }

    /** @return array<string, mixed>|null */
    public function release(string $handle, string $as, ?string $note = null): ?array
    {
        return $this->forIssue('post', $handle, '/release', ['as' => $as, 'note' => $note]);
    }

    /** @return array<string, mixed>|null */
    public function review(string $handle, string $as, string $pr, ?string $note = null): ?array
    {
        return $this->forIssue('post', $handle, '/review', ['as' => $as, 'pr_url' => $pr, 'note' => $note]);
    }

    /** @return array<string, mixed>|null */
    public function comment(string $handle, string $as, string $note): ?array
    {
        return $this->forIssue('post', $handle, '/comment', ['as' => $as, 'note' => $note]);
    }

    /**
     * Every per-issue verb, which is all of them but the queue.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function forIssue(string $method, string $handle, string $path, array $payload = []): ?array
    {
        $this->refusal = null;
        $handle = trim($handle);

        if (preg_match(self::HANDLE, $handle) !== 1) {
            return $this->refuse(sprintf(
                '"%s" is not a handle any board answers to. Try CFB-12, a bare id, or the advisor\'s key.',
                $handle,
            ));
        }

        // `null` is dropped rather than sent: an absent note and an empty one
        // are different facts, and the board's validator reads them that way.
        $body = $this->call($method, "/{$handle}{$path}", array_filter($payload, fn (mixed $v): bool => $v !== null), $handle);

        return $body === null ? null : (array) ($body['issue'] ?? []);
    }

    /**
     * One request, and every way it can fail said in words.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function call(string $method, string $path, array $payload, ?string $handle): ?array
    {
        if (! Str::startsWith($this->origin, ['https://', 'http://'])) {
            return $this->refuse(sprintf(
                'CFB_BOARD_URL is `%s`, which is not an origin. It wants a scheme and a host and nothing else — https://campusfootball.test.',
                $this->origin,
            ));
        }

        if (mb_strlen($this->token) < EnsureOpsToken::MINIMUM_LENGTH) {
            // Worth its own sentence rather than letting the 404 speak: an ops
            // surface with no token configured answers 404 deliberately, which
            // reads exactly like a card that is not there.
            return $this->refuse(sprintf(
                'CFB_BOARD_URL points at %s, but this checkout has no usable OPS_TOKEN. Every /ops route answers 404 '
                .'without one, and that is indistinguishable from a card that does not exist.',
                $this->origin,
            ));
        }

        try {
            $response = Http::withHeaders([EnsureOpsToken::HEADER => $this->token])
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->{$method}($this->origin.self::PATH.$path, $payload);
        } catch (Throwable $e) {
            return $this->refuse(sprintf(
                'The board at %s did not answer (%s). Nothing was written there — and nothing was written here either, '
                .'because a checkout with a board URL has one board and never falls back to its own table.',
                $this->origin,
                $this->scrubbed($e->getMessage()),
            ));
        }

        return match (true) {
            $response->status() === 409 => $this->held($response->json(), $handle),
            $response->status() === 401 => $this->refuse(sprintf(
                'The board at %s refused the token (401). OPS_TOKEN here is not OPS_TOKEN there.',
                $this->origin,
            )),
            $response->status() === 404 => $this->missing($handle),
            $response->status() === 422 => $this->refuse(sprintf(
                'The board at %s would not take that (422). %s',
                $this->origin,
                $this->firstError($response->json()),
            )),
            $response->status() === 429 => $this->refuse(sprintf(
                'The board at %s is rate-limiting this checkout (429). /ops allows a bounded number of attempts a '
                .'minute; wait one out and run it again.',
                $this->origin,
            )),
            ! $response->successful() => $this->refuse(sprintf(
                'The board at %s answered %d. Nothing was written here — this checkout has one board and does not '
                .'fall back to its own table.',
                $this->origin,
                $response->status(),
            )),
            ! is_array($response->json()) => $this->refuse(sprintf(
                'The board at %s answered %d but not with JSON, so it is probably not this application.',
                $this->origin,
                $response->status(),
            )),
            default => $response->json(),
        };
    }

    /**
     * The double-assign refusal, worded the way the local one is so the two
     * surfaces read the same to whoever hits it.
     */
    private function held(mixed $body, ?string $handle): null
    {
        $body = is_array($body) ? $body : [];
        $expires = is_string($body['expires_at'] ?? null) ? Carbon::parse($body['expires_at'])->diffForHumans() : 'no stated end';

        return $this->refuse(sprintf(
            '%s is held by %s (%s) on the board at %s. Nothing here steals a claim.',
            $handle ?? 'That issue',
            is_string($body['by'] ?? null) ? $body['by'] : 'somebody else',
            $expires,
            $this->origin,
        ));
    }

    /**
     * A 404 is genuinely two answers, so it says both rather than picking one.
     * The token check above rules out the unconfigured case for THIS checkout;
     * it cannot rule it out for the deployment on the other end.
     */
    private function missing(?string $handle): null
    {
        return $this->refuse($handle === null
            ? sprintf(
                'The board at %s has no /ops/issues to read. Either it is not this application, or it has no '
                .'OPS_TOKEN configured — an ops surface nobody set up does not announce that it exists.',
                $this->origin,
            )
            : sprintf(
                'Nothing matches "%s" on the board at %s — or that deployment has no OPS_TOKEN configured, which '
                .'answers 404 for the same reason. A card filed on another board never resolves here.',
                $handle,
                $this->origin,
            ));
    }

    /**
     * The board's own validation message, which is the useful half of a 422.
     * Field names and messages only; the token was never in the body.
     */
    private function firstError(mixed $body): string
    {
        if (! is_array($body)) {
            return 'It said no more than that.';
        }

        foreach ((array) ($body['errors'] ?? []) as $messages) {
            foreach ((array) $messages as $message) {
                if (is_string($message) && $message !== '') {
                    return $message;
                }
            }
        }

        return is_string($body['message'] ?? null) ? $body['message'] : 'It said no more than that.';
    }

    /**
     * The token, out of anything we did not write.
     *
     * A transport failure's message comes from cURL and carries the URL, not
     * the headers — so this is belt and braces. It is one line, and it turns
     * "a token cannot end up in a log" from an assumption into a guarantee.
     */
    private function scrubbed(string $message): string
    {
        return $this->token === '' ? $message : str_replace($this->token, '[ops token]', $message);
    }

    /** Says why, and returns null so every caller can `?? refuse`. */
    private function refuse(string $message): null
    {
        $this->refusal = $message;

        return null;
    }
}
