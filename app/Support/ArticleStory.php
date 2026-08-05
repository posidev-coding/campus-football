<?php

namespace App\Support;

use App\Models\Athlete;
use App\Models\Team;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Cache;

/**
 * Turns ESPN's stored story markup into HTML this app is willing to render.
 *
 * Three jobs, and the order matters — placeholders first, then rewriting, then
 * the allowlist, so nothing introduced by an earlier pass escapes the last one.
 *
 * 1. RESOLVE ESPN'S OWN PSEUDO-TAGS. A story is not plain HTML: it carries
 *    `<photo1>`, `<inline1>`, `<video1>`, `<alsosee>` — placeholders their
 *    renderer fills in. Left alone they are unknown elements the browser keeps
 *    as empty inline nodes, so the page shows stray blank paragraphs where the
 *    reader expects something. Observed live: `alsosee` on 8 of 18 sampled
 *    stories, `photoN` on 4, `inlineN` on 4.
 *
 * 2. BRING THE LINKS HOME. Every team and player reference points at espn.com.
 *    Rendering those as-is makes an in-app reader a worse version of the
 *    website it replaced — the whole point is that a reader stays here. Team
 *    and athlete links resolve to OUR pages when we know the id.
 *
 * 3. SANITIZE. This is third-party HTML rendered unescaped, which is exactly
 *    the shape of a stored XSS. Everything runs through a tag and attribute
 *    ALLOWLIST — deny-by-default, so a tag ESPN adds next season is dropped
 *    rather than trusted.
 */
class ArticleStory
{
    /**
     * Tags a story may keep. Anything absent is UNWRAPPED (children survive,
     * the element does not) rather than deleted, so an unexpected wrapper
     * cannot silently eat a paragraph of text.
     */
    private const ALLOWED = [
        'p', 'br', 'a', 'em', 'strong', 'b', 'i', 'u', 'span',
        'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'hr',
        'figure', 'figcaption', 'img',
    ];

    /** Tags dropped ENTIRELY, children and all. */
    private const DISCARDED = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input'];

    /**
     * Per-tag attribute allowlist. Everything else goes — including `class`,
     * `align` and `name`, which ESPN uses for its own layout and in-page
     * anchors and which mean nothing in our stylesheet.
     */
    private const ATTRIBUTES = [
        'a' => ['href'],
        'img' => ['src', 'alt'],
    ];

    /** ESPN's placeholders. `photoN` resolves; the rest are promos we drop. */
    private const PLACEHOLDER = '/<(alsosee|inline\d+|video\d+|photo\d+)\s*\/?>/i';

    /**
     * @param  list<array{url: string, caption: ?string, credit: ?string}>  $images
     */
    public static function render(?string $story, array $images = []): string
    {
        if ($story === null || trim($story) === '') {
            return '';
        }

        $html = self::resolvePlaceholders($story, $images);

        $dom = new DOMDocument;

        /*
         * `loadHTML` complains about ESPN's own markup — unknown elements,
         * unclosed tags — and none of it is actionable, so warnings are
         * captured rather than escalated. The meta charset is what stops
         * libxml reading UTF-8 bytes as Latin-1 and mangling every curly
         * quote and accented name.
         */
        $previous = libxml_use_internal_errors(true);

        $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div id="story">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('story');

        if ($root === null) {
            return '';
        }

        self::clean($root, self::linkMap($html));

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Swap `<photoN>` for the image it names and drop the promo placeholders.
     *
     * A placeholder normally sits alone inside its own `<p>`, so the paragraph
     * goes with it — otherwise removing the tag leaves an empty paragraph
     * behind, which is the visible symptom rather than the invisible one.
     *
     * @param  list<array{url: string, caption: ?string, credit: ?string}>  $images
     */
    private static function resolvePlaceholders(string $story, array $images): string
    {
        $story = preg_replace_callback(
            '/<p>\s*'.substr(self::PLACEHOLDER, 1, -2).'\s*<\/p>/i',
            fn (array $m) => self::placeholder($m[1], $images),
            $story
        ) ?? $story;

        // Any left over were inline rather than in a paragraph of their own.
        return preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $m) => self::placeholder($m[1], $images),
            $story
        ) ?? $story;
    }

    /**
     * @param  list<array{url: string, caption: ?string, credit: ?string}>  $images
     */
    private static function placeholder(string $tag, array $images): string
    {
        if (! preg_match('/^photo(\d+)$/i', $tag, $m)) {
            // `alsosee`, `inlineN`, `videoN`: ESPN's own cross-promotion, which
            // is precisely the thing an in-app reader is here to avoid.
            return '';
        }

        $image = $images[(int) $m[1]] ?? null;

        if ($image === null || empty($image['url'])) {
            return '';
        }

        $caption = trim((string) ($image['caption'] ?? ''));
        $credit = trim((string) ($image['credit'] ?? ''));

        $figure = '<figure><img src="'.e($image['url']).'" alt="'.e($caption).'">';

        if ($caption !== '' || $credit !== '') {
            $figure .= '<figcaption>'.e(trim($caption.($credit !== '' ? "  {$credit}" : ''))).'</figcaption>';
        }

        return $figure.'</figure>';
    }

    /**
     * ESPN team and athlete URLs found in the story, mapped to our own routes.
     *
     * Two queries for a whole article, never one per link, and only for ids the
     * story actually mentions. `teams.id` IS the ESPN id, which is what makes
     * this a lookup rather than a matching problem.
     *
     * @return array<string, string>
     */
    private static function linkMap(string $html): array
    {
        preg_match_all(
            '#espn\.com/college-football/(team|player)/[^"\']*?/id/(\d+)#i',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        if ($matches === []) {
            return [];
        }

        $teamIds = [];
        $athleteIds = [];

        foreach ($matches as [, $kind, $id]) {
            if (strtolower($kind) === 'team') {
                $teamIds[] = (int) $id;
            } else {
                $athleteIds[] = (int) $id;
            }
        }

        $map = [];

        foreach (Team::whereIn('id', array_unique($teamIds))->get(['id', 'slug']) as $team) {
            $map["team:{$team->id}"] = route('team', $team);
        }

        foreach (Athlete::whereIn('id', array_unique($athleteIds))->pluck('id') as $id) {
            $map["player:{$id}"] = route('player', $id);
        }

        return $map;
    }

    /**
     * Walk the tree and enforce the allowlists.
     *
     * Iterates over a SNAPSHOT of the children, because unwrapping a node
     * mutates the live NodeList underneath the loop and silently skips
     * siblings — the classic way a sanitizer lets something through.
     *
     * @param  array<string, string>  $links
     */
    private static function clean(DOMNode $node, array $links): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DISCARDED, true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            self::clean($child, $links);

            if (! in_array($tag, self::ALLOWED, true)) {
                self::unwrap($child);

                continue;
            }

            self::cleanAttributes($child, $tag, $links);

            self::pruneIfEmpty($child, $tag);
        }
    }

    /**
     * Drop a paragraph that no longer contains anything.
     *
     * Cleaning creates these: ESPN wraps its in-page anchors and promos in
     * their own `<p>`, so unwrapping `<a name="sec">` or removing an
     * `<alsosee>` leaves the paragraph behind as a blank gap in the prose.
     * Measured on one conference roundup: seven of them.
     *
     * Only containers whose whole job is holding text — an empty `<figure>` or
     * `<li>` would mean a resolved image was dropped, and silently swallowing
     * that would hide the bug rather than show it.
     */
    private static function pruneIfEmpty(DOMElement $element, string $tag): void
    {
        if (! in_array($tag, ['p', 'h2', 'h3', 'h4', 'blockquote'], true)) {
            return;
        }

        if (trim($element->textContent) !== '') {
            return;
        }

        // An image or a line break is content even with no text beside it.
        foreach (['img', 'figure', 'br'] as $meaningful) {
            if ($element->getElementsByTagName($meaningful)->length > 0) {
                return;
            }
        }

        $element->parentNode?->removeChild($element);
    }

    /**
     * @param  array<string, string>  $links
     */
    private static function cleanAttributes(DOMElement $element, string $tag, array $links): void
    {
        $allowed = self::ATTRIBUTES[$tag] ?? [];

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            if (! in_array(strtolower($attribute->nodeName), $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        if ($tag === 'a') {
            self::cleanLink($element, $links);
        }

        if ($tag === 'img' && ! self::isSafeUrl($element->getAttribute('src'))) {
            $element->parentNode?->removeChild($element);
        }
    }

    /**
     * @param  array<string, string>  $links
     */
    private static function cleanLink(DOMElement $element, array $links): void
    {
        $href = $element->getAttribute('href');

        if (preg_match('#espn\.com/college-football/(team|player)/[^"\']*?/id/(\d+)#i', $href, $m)) {
            $key = strtolower($m[1]).':'.(int) $m[2];

            if (isset($links[$key])) {
                // Ours: same tab, no `rel` needed, and it keeps the reader in
                // the app rather than handing them to the site we replaced.
                $element->setAttribute('href', $links[$key]);

                return;
            }
        }

        if (! self::isSafeUrl($href)) {
            self::unwrap($element);

            return;
        }

        $element->setAttribute('target', '_blank');
        $element->setAttribute('rel', 'noopener noreferrer nofollow');
    }

    /**
     * Absolute http(s) only.
     *
     * This is the check that matters most: `javascript:` in an href is the
     * cheapest stored XSS there is, and a scheme-relative `//evil.test` reads
     * as a path to a naive filter.
     */
    private static function isSafeUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://[^/]#i', trim($url));
    }

    /**
     * Replace an element with its own children.
     */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * Rendered HTML, memoized — sanitizing a 28 KB story parses a DOM, and the
     * result only changes when the stored story does.
     *
     * Cached as a plain STRING. Nothing else is safe to put in the cache.
     */
    public static function cached(int $articleId, ?string $story, array $images = [], ?int $version = null): string
    {
        if ($story === null || trim($story) === '') {
            return '';
        }

        return Cache::remember(
            "article:story:{$articleId}:".($version ?? crc32($story)),
            now()->addDay(),
            fn () => self::render($story, $images)
        );
    }
}
