<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * An invite link as a square somebody can point a phone at.
 *
 * The reason it exists: the office pool is announced in Slack, read on a
 * DESKTOP, and has to be joined on a PHONE — there is no share sheet across
 * that gap and retyping an 8-character code is the step people abandon.
 *
 * SVG, rendered INLINE, for three reasons that are all the same reason.
 * It is sharp at any size on any screen; it costs no second request (and so
 * cannot 404 inside an offline shell); and, decisively, it means the join
 * URL is never handed to a third-party QR service. Every "free QR API" is a
 * log of who is inviting whom.
 *
 * The white plate is the renderer's own background rect and is KEPT, in
 * both themes. A QR inverted for dark mode is materially worse at being
 * scanned — many phone cameras will not read light-on-dark at all — and it
 * fails looking like a broken code rather than a theming choice.
 */
class QrCode
{
    /**
     * The rendered edge in SVG user units. Nothing reads it as a pixel
     * size — the markup's width/height are stripped so CSS decides — but
     * the renderer needs a coordinate space and 256 keeps the module math
     * on tidy numbers.
     */
    private const CANVAS = 256;

    /**
     * Four modules of quiet zone, which is what the QR spec requires. It
     * looks like wasted padding and is not: a scanner locates the symbol
     * by finding blank space around it, and trimming this is the single
     * most common way a hand-made QR ends up unreadable.
     */
    private const QUIET_ZONE = 4;

    /**
     * Inline `<svg>` markup for a URL, safe to echo unescaped.
     *
     * The payload is encoded into the module matrix, never written into
     * the markup as text, so the output is path data and nothing else —
     * there is no injection surface here even though the caller echoes it
     * with `{!! !!}`.
     *
     * `width`/`height` are stripped and the `viewBox` left alone so the
     * square scales to whatever box Tailwind gives it. A fixed 256 attribute
     * would ignore the class and overflow at 390.
     */
    public static function svg(string $url): string
    {
        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(self::CANVAS, self::QUIET_ZONE),
                new SvgImageBackEnd,
            ),
        );

        $svg = $writer->writeString($url);

        // The XML prolog is valid in a standalone file and invalid inside
        // an HTML document, where it parses as a bogus comment.
        $svg = preg_replace('/<\?xml.*?\?>\s*/s', '', $svg) ?? $svg;

        return preg_replace('/\s(width|height)="\d+"/', '', $svg, 2) ?? $svg;
    }
}
