<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Whether a request comes from a person or from software that names itself.
 *
 * One judgement, two jobs. RecordActivity uses it to file a page view under
 * the automated audience rather than as a new guest. Screens use it to decide
 * whether a view may spend an upstream request on the reader's behalf: a
 * crawler re-reading a player page every few minutes must not arm an ESPN
 * fetch nobody will read (CFB-111).
 *
 * A matching list, not a verdict. A bot that dresses as a browser still reads
 * as a person, which is why the traffic report also publishes how many guest
 * visitors made exactly one request.
 */
final class AutomatedAgents
{
    /**
     * User-agents that announce software rather than a person: search and AI
     * crawlers, chat and social link unfurlers, uptime monitors, headless
     * browsers and plain HTTP clients.
     */
    public const PATTERN = '/bot\b|bot\/|crawl|spider|slurp|facebookexternalhit|embedly|preview|whatsapp|headless|lighthouse|pingdom|uptime|monitor|curl\/|wget\/|python-requests|python-urllib|go-http-client|okhttp|axios\/|node-fetch|java\/|libwww|httpclient/i';

    /**
     * Does this client say it is software?
     *
     * A client with no user-agent at all, or one that names a crawler, an
     * unfurler, a monitor or an HTTP library. None of them keep the session
     * cookie. Reads the user-agent only — who is signed in is the caller's
     * question, see isPerson().
     */
    public static function matches(Request $request): bool
    {
        $agent = trim((string) $request->userAgent());

        return $agent === '' || preg_match(self::PATTERN, $agent) === 1;
    }

    /**
     * Is a person asking?
     *
     * An account is a person whatever their browser says about itself, so a
     * signed-in request always is. A signed-out one is unless its user-agent
     * names software.
     */
    public static function isPerson(Request $request): bool
    {
        return $request->user() !== null || ! self::matches($request);
    }
}
