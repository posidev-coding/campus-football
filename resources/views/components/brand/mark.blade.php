@props(['mono' => false])

{{--
    The pennant — the app's own mark, and the thing that finally tells it apart
    from the League tab. Both were the Flux `trophy` glyph until now, which is
    the one shape a brand mark must not share with a navigation icon.

    Drawn INLINE rather than loaded as a file, for two reasons that both matter:
    an <img> cannot inherit `currentColor`, so a single file would need a light
    and a dark copy; and an inline path is retinted by the CSS custom properties
    the App Branding page overrides, so a color change moves the mark without
    touching an asset.

    `aria-hidden` throughout: everywhere this is used, a wordmark or a heading
    beside it already names the thing, so announcing it again is noise.
--}}
@if (App\Support\Brand::hasCustom('mark-light') || App\Support\Brand::hasCustom('mark-dark'))
    {{-- An uploaded mark renders as an image PAIR, never inlined. Echoing
         uploaded SVG unescaped into the page is a stored-XSS shape, and the
         admin-only upload path does not change that — the article renderer
         already pays for a tag allowlist and this does not need a second one.
         The cost is `currentColor` theming, which is why there are two slots:
         a custom mark supplies its own light and dark artwork. --}}
    <img
        src="{{ App\Support\Brand::asset('mark-light') ?? App\Support\Brand::asset('mark-dark') }}"
        alt=""
        aria-hidden="true"
        {{ $attributes->class(['dark:hidden']) }}
    >
    <img
        src="{{ App\Support\Brand::asset('mark-dark') ?? App\Support\Brand::asset('mark-light') }}"
        alt=""
        aria-hidden="true"
        {{ $attributes->class(['hidden dark:block']) }}
    >
@else
    <svg
        viewBox="0 0 100 100"
        xmlns="http://www.w3.org/2000/svg"
        aria-hidden="true"
        {{ $attributes->class(['text-brand-ink dark:text-brand-cream']) }}
    >
        {{-- Pole, then the pennant flying off it. --}}
        <rect x="8.5" y="6" width="9.5" height="88" rx="1.2" fill="currentColor" />
        <polygon points="18,13 93,39 18,65" fill="currentColor" />

        {{-- Two stripes ACROSS the pennant, in Lager. `mono` paints them in
             the body color instead, for the one-color contexts — a favicon at
             16px merges them, and a mark sitting on a colored surface has no
             second color to spare. --}}
        <rect x="24" y="30" width="28" height="5.5" @class(['fill-brand-lager' => ! $mono, 'fill-current' => $mono]) />
        <rect x="24" y="41.5" width="44" height="5.5" @class(['fill-brand-lager' => ! $mono, 'fill-current' => $mono]) />
    </svg>
@endif
