<?php

namespace App\Enums;

/**
 * How wide the screen was, in five buckets — the rollup dimension behind
 * "who is reading this on a phone".
 *
 * The whole product is designed at 390px and widened from there, so "which
 * width" is the first question asked of any attention number: a screen that
 * only works above `sm` reads as healthy in a total and as broken in this
 * breakdown. `client_errors.viewport` already stores the raw width for the
 * same reason; this is that column bucketed, once, at rollup.
 *
 * UNKNOWN IS A REAL CATEGORY. The first HTML response of a session is sent
 * before the client cookie exists, so a genuine share of views have no width
 * at all — and the app never writes a default where data is missing. Bucketing
 * those as Phone because most readers are on a phone would be inventing the
 * exact number the bucket exists to measure. They are `Unknown`, they are
 * reported as "not reported", and the honest denominator is visible beside
 * every rate.
 *
 * The boundaries are Tailwind's, not new ones: 768 is `md` and 1024 is `lg`,
 * so a bucket boundary is a breakpoint a layout actually changes at. `Compact`
 * splits below 400 because that is where the design's own floor sits — a 390
 * iPhone and a 360 Android are the same bucket, and a 320px SE is the one that
 * breaks.
 */
enum ViewportBucket: int
{
    /** Not reported — no cookie yet, or a width outside every sane range. */
    case Unknown = 0;

    /** Under 400: the design floor, where anything that overflows shows. */
    case Compact = 1;

    /** 400 to 767: the phone the product is actually read on. */
    case Phone = 2;

    /** 768 to 1023: Tailwind's `md`. */
    case Tablet = 3;

    /** 1024 and up: Tailwind's `lg`. */
    case Desktop = 4;

    /**
     * Bucket a raw width. Null in, `Unknown` out — never a guess, and never
     * the nearest bucket.
     */
    public static function for(?int $width): self
    {
        return match (true) {
            $width === null, $width < 1 => self::Unknown,
            $width < 400 => self::Compact,
            $width < 768 => self::Phone,
            $width < 1024 => self::Tablet,
            default => self::Desktop,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not reported',
            self::Compact => 'Compact phone',
            self::Phone => 'Phone',
            self::Tablet => 'Tablet',
            self::Desktop => 'Desktop',
        };
    }
}
