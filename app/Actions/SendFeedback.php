<?php

namespace App\Actions;

use App\Enums\FeedbackKind;
use App\Exceptions\FeedbackTooFast;
use App\Http\Controllers\ClientErrorController;
use App\Models\Feedback;
use App\Models\User;
use App\Support\Release;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * One note from a reader, into the feedback table.
 *
 * Every gate lives HERE — a body that survives trimming, the column's own
 * width, and the hourly limiter — because this is reachable from a public
 * Livewire method and a modal that is closed is presentation, not
 * enforcement. The same doctrine {@see PostToConversation} follows.
 *
 * The CONTEXT is the browser's word and is treated that way. Which page, how
 * wide, installed or not: reduced, clamped and truncated, never a reason to
 * refuse — the note is the thing worth having, and a 422 over a made-up
 * viewport would throw away the only witness a bug has. The page is kept as a
 * PATH: a query string is where a signed link or an invite code rides. That is
 * {@see ClientErrorController}'s rule, applied to a
 * report a person typed.
 *
 * The body is the one thing a person typed, so it REJECTS past the width
 * rather than truncating: the thousand-and-first character is a refusal the
 * writer can see, not a silent edit of what they said.
 */
class SendFeedback
{
    /**
     * The textarea's own maxlength, checked here so the two cannot drift —
     * the browser stops the typing, this stops the request.
     */
    public const MAX_LENGTH = 1000;

    /**
     * Spelled out in seconds, never `now()->addHour()->diffInSeconds()` —
     * that is NEGATIVE in Carbon 3, which expires the key the instant it is
     * written and makes the limiter permit everything. It would fail OPEN.
     */
    public const WINDOW = 3600;

    public const MAX_PER_WINDOW = 5;

    /** The `release` column's width; a tag is `v4.0.0-beta.11`-sized. */
    private const RELEASE_WIDTH = 20;

    /**
     * @param  array{path?: mixed, viewport?: mixed, standalone?: mixed, user_agent?: mixed}  $context
     *
     * @throws FeedbackTooFast when the reader is inside the hourly cap
     * @throws InvalidArgumentException when the body is empty or over the width
     */
    public function handle(User $user, FeedbackKind $kind, string $body, array $context = []): Feedback
    {
        $body = trim($body);

        if ($body === '') {
            throw new InvalidArgumentException('A note needs something in it.');
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A note is at most '.self::MAX_LENGTH.' characters.');
        }

        $key = "feedback:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_WINDOW)) {
            throw new FeedbackTooFast(RateLimiter::availableIn($key));
        }

        // Hit only on the way to a real row: a refused note must not spend
        // the reader's hour, or an empty tap costs them the note after it.
        RateLimiter::hit($key, self::WINDOW);

        return Feedback::query()->create([
            'user_id' => $user->id,
            'kind' => $kind,
            'body' => $body,
            'path' => self::path($context['path'] ?? null),
            // Null when there is no stamp: Release never invents one, and
            // neither does this row.
            'release' => self::text(Release::tag(), self::RELEASE_WIDTH),
            'viewport' => self::viewport($context['viewport'] ?? null),
            'standalone' => filter_var($context['standalone'] ?? false, FILTER_VALIDATE_BOOL),
            'user_agent' => self::text($context['user_agent'] ?? null, 255),
        ]);
    }

    /**
     * The PATH of the page and nothing else, cut to the column.
     *
     * `parse_url` rather than a split on `?`: a fragment, a scheme or a host
     * pasted in by a browser extension all fall away the same way.
     */
    private static function path(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $only = parse_url(trim($path), PHP_URL_PATH);

        if (! is_string($only) || $only === '') {
            return null;
        }

        return mb_substr($only, 0, 255);
    }

    /** What the reader could see, clamped to the column's unsigned smallint. */
    private static function viewport(mixed $viewport): ?int
    {
        if (! is_numeric($viewport)) {
            return null;
        }

        return max(0, min(65535, (int) $viewport));
    }

    private static function text(mixed $value, int $width): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $width);
    }
}
