<?php

namespace App\Support;

use App\Enums\ContentRating;

/**
 * The deterministic read-through between the model and the reader's inbox.
 *
 * It exists because the guard that matters here cannot be a prompt. A prompt is
 * a request; this is a check, it costs nothing, it runs on every recap, and it
 * cannot have an off day. Anything it rejects is replaced by the deterministic
 * `mail.newsletter` copy — real content that was already good enough to ship,
 * never an invented substitute and never an error.
 *
 * SO IT ERRS TOWARD REJECTION. A false positive costs one reader one week of
 * generated copy and nobody notices. A false negative is a joke about somebody
 * in their inbox with our name on it, and it is also the App Store age rating.
 * Those are not the same price.
 *
 * What it checks, in the order the damage runs:
 *
 *   1. SHAPE      — a headline and paragraphs, all strings, none empty
 *   2. LENGTH     — an email intro, not an essay
 *   3. MARKUP     — plain sentences; the mail template owns layout
 *   4. THE PERSON — roast the pick, the team, the record. Never the reader.
 *   5. REGISTER   — no profanity, at any level
 *   6. SPELLING   — American, everywhere
 *   7. GEORGIA    — only ever as live data
 */
class RecapSweep
{
    public const MAX_HEADLINE = 80;

    public const MAX_PARAGRAPHS = 3;

    public const MAX_PARAGRAPH = 320;

    public const MAX_TOTAL = 900;

    /**
     * Attacks on the READER, which is the one thing no register licenses.
     *
     * "Your team is a dumpster fire" is the product working. "You're an idiot
     * for following them" is the product becoming a liability, and the two are
     * one pronoun apart — which is exactly why this is a pattern list and not a
     * word list. `your <noun>` is fine by design; `you are <noun>` is not.
     *
     * @var list<string>
     */
    private const ATTACKS = [
        '/\byou(?:\'re|\sare|\ssound|\slook|\sseem|\swere)\b[^.!?]{0,30}\b(?:idiot|moron|loser|fool|clown|pathetic|stupid|dumb|worthless|failure|embarrassment|disgrace|delusional|useless|hopeless|garbage|trash)\b/iu',
        '/\byou\s+(?:suck|stink|blew\sit|deserve\s(?:this|it|that)|are\sthe\sproblem|have\sno\s(?:idea|clue|taste))\b/iu',
        // The reader's life rather than the reader's football. Nothing about a
        // Saturday needs any of these words.
        '/\byour\s+(?:family|mother|father|mom|dad|wife|husband|kids?|children|face|body|weight|looks|job|career|life|marriage|intelligence|iq)\b/iu',
    ];

    /**
     * Banned at EVERY register, R included.
     *
     * A deliberate product line, not an oversight: this app's registers differ
     * in attitude, not in vocabulary. Every `r` line in {@see Voice} is clean,
     * so generated copy that swore would be louder than anything a human wrote
     * here — and the App Store age rating is decided by the loudest thing in
     * the build, not the average one.
     *
     * @var list<string>
     */
    private const STRONG = [
        '\bf+u+c+k\w*', '\bsh[i1]t\w*', '\bb[i1]tch\w*', '\bbastard\w*', '\bcunt\w*',
        '\bd[i1]ck(?:head|s)?\b', '\bpiss\w*', '\bwhore\w*', '\bslut\w*', '\bprick\b',
        '\bass(?:hole|hat|es)\b', '\bjackass\b', '\bgoddamn\w*', '\bwtf\b', '\bstfu\b',
    ];

    /**
     * Banned at PG only — PG-13's own description licenses "occasional mild
     * profanity", and R inherits it.
     *
     * @var list<string>
     */
    private const MILD = [
        '\bdamn\w*', '\bhell\b', '\bass\b', '\bcrap\w*', '\bsuck(?:s|ed|ing)?\b', '\bscrewed\b',
    ];

    /**
     * British forms, with `defence` and `offence` at the front because they are
     * the two a football writer reaches for without noticing.
     *
     * @var list<string>
     */
    private const BRITISH = [
        'defence', 'offence', 'favourite', 'colour', 'centre', 'honour', 'humour',
        'rumour', 'neighbour', 'realise', 'recognise', 'apologise', 'organise',
        'analyse', 'criticise', 'travelling', 'travelled', 'cancelled', 'cancelling',
        'marvellous', 'grey', 'theatre', 'metre', 'sceptical', 'whilst', 'practise',
        'licence', 'programme',
    ];

    /**
     * Everything wrong with this recap. Empty means it may be sent.
     *
     * @param  array{headline: string, body: list<string>}  $recap
     * @param  string  $facts  The prompt's fact block — the only place a name
     *                         we would otherwise never print is allowed to
     *                         have come from.
     * @return list<string>
     */
    public function reasons(array $recap, ContentRating $rating, string $facts = ''): array
    {
        $headline = $recap['headline'] ?? '';
        $body = $recap['body'] ?? [];

        if (! is_string($headline) || trim($headline) === '') {
            return ['the headline is empty'];
        }

        if (! is_array($body) || $body === []) {
            return ['the body is empty'];
        }

        foreach ($body as $paragraph) {
            if (! is_string($paragraph) || trim($paragraph) === '') {
                return ['a paragraph is empty'];
            }
        }

        $text = $headline."\n".implode("\n", $body);

        return [
            ...$this->lengths($headline, $body),
            ...$this->markup($text),
            ...$this->attacks($text),
            ...$this->register($text, $rating),
            ...$this->spelling($text),
            ...$this->georgia($text, $facts),
        ];
    }

    /**
     * @param  list<string>  $body
     * @return list<string>
     */
    private function lengths(string $headline, array $body): array
    {
        $reasons = [];
        $total = mb_strlen($headline);

        if (mb_strlen($headline) > self::MAX_HEADLINE) {
            $reasons[] = 'the headline runs past '.self::MAX_HEADLINE.' characters';
        }

        if (count($body) > self::MAX_PARAGRAPHS) {
            $reasons[] = 'there are more than '.self::MAX_PARAGRAPHS.' paragraphs';
        }

        foreach ($body as $paragraph) {
            $total += mb_strlen($paragraph);

            if (mb_strlen($paragraph) > self::MAX_PARAGRAPH) {
                $reasons[] = 'a paragraph runs past '.self::MAX_PARAGRAPH.' characters';
                break;
            }
        }

        if ($total > self::MAX_TOTAL) {
            $reasons[] = 'the whole recap runs past '.self::MAX_TOTAL.' characters';
        }

        return $reasons;
    }

    /**
     * The mail template owns layout, so prose that arrives carrying its own is
     * either going to render wrong or render something we did not write.
     *
     * @return list<string>
     */
    private function markup(string $text): array
    {
        $reasons = [];

        if (str_contains($text, '<')) {
            $reasons[] = 'it contains markup';
        }

        if (preg_match('/^\s*(?:#|[-*]\s)/m', $text) === 1) {
            $reasons[] = 'it contains a heading or a bullet';
        }

        if (str_contains($text, '](') || str_contains($text, 'http')) {
            $reasons[] = 'it contains a link';
        }

        // A leaked Voice placeholder — `:points`, `:week`. The few-shot lines
        // are filtered for these, and this is the other end of that guard.
        if (preg_match('/(?<!\w):[a-z_]{2,}/', $text) === 1) {
            $reasons[] = 'it contains an unfilled placeholder';
        }

        if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text) === 1) {
            $reasons[] = 'it contains emoji';
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function attacks(string $text): array
    {
        foreach (self::ATTACKS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return ['it goes after the reader rather than the football'];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function register(string $text, ContentRating $rating): array
    {
        $banned = $rating === ContentRating::Pg
            ? [...self::STRONG, ...self::MILD]
            : self::STRONG;

        foreach ($banned as $pattern) {
            if (preg_match('/'.$pattern.'/iu', $text) === 1) {
                return ['it is louder than '.$rating->label().' allows'];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function spelling(string $text): array
    {
        foreach (self::BRITISH as $word) {
            if (preg_match('/\b'.$word.'\b/iu', $text) === 1) {
                return ['it spells "'.$word.'" the British way'];
            }
        }

        return [];
    }

    /**
     * The pilot audience is Tennessee alumni, and Georgia is their rival: it
     * may reach a screen as live data and never as a joke, an example or an
     * aside. `GuidedTourTest` sweeps the tour copy for the same word — this is
     * the same rule applied to copy nobody reviewed before it sent.
     *
     * @return list<string>
     */
    private function georgia(string $text, string $facts): array
    {
        if (str_contains($facts, 'Georgia')) {
            return [];
        }

        return preg_match('/\bGeorgia\b/iu', $text) === 1
            ? ['it names Georgia, which is not in this reader\'s week']
            : [];
    }
}
