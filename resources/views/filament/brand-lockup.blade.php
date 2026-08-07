@props(['dark' => false])

@php
    use App\Support\Brand;

    $wordmark = Brand::wordmark();

    /*
     * INLINE STYLES ONLY, and that is not a preference.
     *
     * The panel ships its own compiled stylesheet and does NOT load
     * resources/css/app.css, so a Tailwind utility written here has no
     * definition behind it — the first Sync Health page laid itself out with
     * `grid grid-cols-2 gap-4` and rendered as one unaligned column. Anything
     * that needs real classes needs a registered Filament theme first.
     *
     * Filament renders this inline inside a div sized by brandLogoHeight, and
     * renders the dark variant as a sibling with its own .fi-logo-dark class —
     * so the light/dark switch is Filament's, not ours, and the flag only picks
     * the two ink colors.
     */
    $body = $dark ? Brand::color('cream') : Brand::color('ink');
    $lead = $dark ? '#a1a1aa' : '#71717a';
@endphp

<span style="display:flex;align-items:center;gap:0.5rem;height:100%;">
    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="height:100%;width:auto;flex-shrink:0;">
        <rect x="8.5" y="6" width="9.5" height="88" rx="1.2" fill="{{ $body }}" />
        <polygon points="18,13 93,39 18,65" fill="{{ $body }}" />
        <rect x="24" y="30" width="28" height="5.5" fill="{{ Brand::color('lager') }}" />
        <rect x="24" y="41.5" width="44" height="5.5" fill="{{ Brand::color('lager') }}" />
    </svg>

    <span style="display:flex;flex-direction:column;align-items:flex-start;line-height:1;">
        <span style="font-size:0.5rem;font-weight:600;letter-spacing:0.3em;text-transform:uppercase;color:{{ $lead }};white-space:nowrap;">
            {{ $wordmark['lead'] }}
        </span>
        <span style="font-size:1.0625rem;font-weight:800;letter-spacing:-0.025em;color:{{ $body }};white-space:nowrap;">
            {{ $wordmark['main'] }}
        </span>
    </span>
</span>
