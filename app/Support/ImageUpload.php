<?php

namespace App\Support;

/**
 * The one number a user-uploaded image is measured against, and the one
 * sentence that says so when it is too big.
 *
 * It lives here rather than on the Livewire concern because the BROWSER
 * needs to read it too, and PHP 8.4 will not hand out a trait's constant.
 *
 * Why the browser needs it at all: PHP discards the entire request body
 * when it exceeds `post_max_size`, and the CSRF token goes with it. An
 * oversized upload therefore never reaches the rule below — the endpoint
 * answers with an HTML error page, Livewire fails to parse it as JSON, and
 * the reader gets a browser alert reading "The page has expired" with no
 * mention of a file. Reported from production on 2026-09-01 against a 22MB
 * PNG and reproduced on a stock checkout: the cliff is PHP's own default,
 * so it is in every environment.
 *
 * A megabyte is generous for a 512px mark and mean enough to stop somebody
 * uploading a photo straight off a phone camera, which is five to ten times
 * that. There is still no resizing pipeline in this app; the cap is the
 * whole defense, and it is enforced where it can be felt.
 */
class ImageUpload
{
    public const MAX_KB = 1024;

    /**
     * The server rule — the backstop for anything that reaches PHP anyway,
     * and the only gate a client with no JavaScript has.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        /*
         * `bail`, because the rules disagree about a HEIC: an iPhone hands
         * one over under `image/*`, Laravel 13's `image` rule ACCEPTS it,
         * and `dimensions` then cannot read it and reports "too small" —
         * a lie about a file the reader could never have fixed by
         * cropping. The mime rule names the four formats the app can
         * actually serve, and `bail` keeps the dimensions message from
         * riding along behind it. The browser control narrows `accept`
         * to the same four, so the picker steers before the rule refuses.
         */
        return ['bail', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.self::MAX_KB, 'dimensions:min_width=64,min_height=64'];
    }

    /** The four formats, as the ACCEPT attribute the browser control wears. */
    public static function accept(): string
    {
        return 'image/jpeg,image/png,image/gif,image/webp';
    }

    /** Plain, like the size message: which formats, and nothing else. */
    public static function mimeMessage(): string
    {
        return 'Use a JPG, PNG, GIF or WebP.';
    }

    /**
     * Plain, because it is an instruction: a reader who cannot tell what to
     * do next after reading it has been told a joke instead of a fact.
     */
    public static function oversizedMessage(): string
    {
        return 'That image is over '.round(self::MAX_KB / 1024).'MB. Crop it or pick a smaller one.';
    }
}
