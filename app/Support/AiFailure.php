<?php

namespace App\Support;

use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Throwable;

/**
 * WHICH way a model call failed, for the log line nobody reads until it matters.
 *
 * Every failure routes to the same place — deterministic content, never an
 * error — so this changes nothing the reader sees. What it changes is the
 * hour somebody spends next February working out why the recaps went quiet,
 * because the two ways to run out of money look nothing alike and neither one
 * says "you are out of money":
 *
 *   OUR limit  400 · `invalid_request_error` · "You have reached your
 *              specified API usage limits". Set by us in the Console, and
 *              raised by us. Falls through every handler in laravel/ai and
 *              arrives as a bare RequestException.
 *   TIER cap   429 · `error.details.error_code = enforced_spend_limit_reached`
 *              · NO retry-after. Anthropic's own ceiling on the account tier.
 *              Wrapped as RateLimitedException, so the body is one link down
 *              the chain — and every SDK retry against it will also fail.
 *
 * The second is the dangerous one to misread: it looks exactly like ordinary
 * rate limiting, which is a thing you wait out.
 */
class AiFailure
{
    /** The Console spend limit we set for ourselves. */
    public const OUR_LIMIT = 'spend-limit-ours';

    /** Anthropic's own cap for the account tier. No retry clears it. */
    public const TIER_CAP = 'spend-limit-tier';

    /** The prepaid balance is empty. */
    public const NO_CREDIT = 'no-credit';

    /** Unreachable or overloaded — the only shape worth trying again. */
    public const UNAVAILABLE = 'provider-unavailable';

    /** Anything else, including a timeout. */
    public const FAILED = 'call-failed';

    public static function classify(Throwable $e): string
    {
        /*
         * The chain, not the top: a 429 is re-thrown as RateLimitedException
         * with a message of its own, and the response body that distinguishes
         * a tier cap from ordinary throttling is on the exception underneath.
         */
        foreach (self::chain($e) as $link) {
            if (! $link instanceof RequestException || $link->response === null) {
                continue;
            }

            $status = $link->response->status();
            $body = $link->response->json();

            if ($status === 400 && str_contains(
                mb_strtolower((string) data_get($body, 'error.message', '')),
                'specified api usage limits',
            )) {
                return self::OUR_LIMIT;
            }

            if ($status === 429 && data_get($body, 'error.details.error_code') === 'enforced_spend_limit_reached') {
                return self::TIER_CAP;
            }
        }

        return match (true) {
            $e instanceof InsufficientCreditsException => self::NO_CREDIT,
            $e instanceof ProviderOverloadedException => self::UNAVAILABLE,
            $e instanceof ProviderConnectionException => self::UNAVAILABLE,
            default => self::FAILED,
        };
    }

    /**
     * A developer sentence for a log. Never copy — what a reader sees comes
     * from Voice, and a reader sees nothing about this anyway.
     */
    public static function describe(Throwable $e): string
    {
        return match (self::classify($e)) {
            self::OUR_LIMIT => 'The Console spend limit we set for ourselves is reached. Raise it at Settings > Billing > Spend limits, or wait for the month to roll.',
            self::TIER_CAP => "Anthropic's own tier cap is reached. No retry clears this one — the account tier has to be raised.",
            self::NO_CREDIT => 'The Console balance is empty. The account is prepaid, so nothing runs until it is topped up.',
            self::UNAVAILABLE => 'The provider was unreachable or overloaded: '.self::trim($e),
            default => 'The model call failed: '.self::trim($e),
        };
    }

    /**
     * @return list<Throwable>
     */
    private static function chain(Throwable $e): array
    {
        $chain = [];

        // Bounded rather than a bare while: an exception chain is data from
        // somebody else's library, and a cycle here would hang a queue worker.
        for ($link = $e, $depth = 0; $link !== null && $depth < 8; $link = $link->getPrevious(), $depth++) {
            $chain[] = $link;
        }

        return $chain;
    }

    private static function trim(Throwable $e): string
    {
        return mb_substr($e->getMessage(), 0, 160);
    }
}
