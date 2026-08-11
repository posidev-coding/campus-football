<?php

namespace App\Support;

/**
 * Phone numbers, stored one way.
 *
 * E.164 — a leading `+`, country code, no spaces or punctuation — because an
 * inbound STOP arrives as a number and nothing else. If what a carrier sends
 * back does not match what we stored character for character, the opt-out finds
 * no user and we keep paying to text somebody who told us to stop.
 *
 * Deliberately NOT a full libphonenumber port. This validates shape and
 * normalizes US-style input, which is the whole audience; a number it cannot
 * parse is rejected at the form rather than guessed at, because a guessed
 * country code is a stranger's phone.
 */
class PhoneNumber
{
    /**
     * Normalize to E.164, or null when it is not a number we can be sure of.
     */
    public static function normalize(?string $input, string $defaultCountry = '1'): ?string
    {
        if (blank($input)) {
            return null;
        }

        $hasPlus = str_starts_with(trim($input), '+');
        $digits = preg_replace('/\D/', '', $input) ?? '';

        if ($digits === '') {
            return null;
        }

        /* Already international: trust the caller's country code, but insist it
           is plausible — E.164 caps the whole thing at 15 digits. */
        if ($hasPlus) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
        }

        /* 11 digits starting with 1 is a US number somebody typed with the
           country code but no plus. */
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        /* The common case: ten digits, area code first. */
        if (strlen($digits) === 10) {
            return '+'.$defaultCountry.$digits;
        }

        /*
         * Anything else is ambiguous — 7 digits is a local number with no area
         * code, 12+ without a plus could be anything. Returning null sends it
         * back to the user rather than inventing a country for them.
         */
        return null;
    }

    /**
     * A number as a person would read it back: +1 (415) 555-0123.
     *
     * US only; everything else is returned as stored, because guessing another
     * country's grouping is worse than not grouping at all.
     */
    public static function format(?string $e164): ?string
    {
        if (blank($e164)) {
            return null;
        }

        if (! preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $m)) {
            return $e164;
        }

        return "+1 ({$m[1]}) {$m[2]}-{$m[3]}";
    }

    /**
     * The last four, for confirming which number we hold without printing it in
     * full on a page somebody might be screen-sharing.
     */
    public static function mask(?string $e164): ?string
    {
        return blank($e164) ? null : '••• '.substr($e164, -4);
    }
}
