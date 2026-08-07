@props([
    'ink' => '#0b0b0c',
    'cream' => '#f5f2ea',
    'lager' => '#e8a33c',
    'lead' => 'CAMPUS',
    'main' => 'Football',
])

{{--
    Inline styles only — the panel does not load resources/css/app.css, so a
    Tailwind class here is a class with nothing behind it.

    Both surfaces at once, deliberately. The palette has a light half and a dark
    half and they fail differently: an Ink close to the page vanishes in light
    mode, a Cream close to the page vanishes in dark. Showing one at a time
    means finding out about the other half after saving.
--}}
<div style="display:grid;gap:0.75rem;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));">
    @foreach ([['bg' => '#ffffff', 'body' => $ink, 'lead' => '#71717a', 'label' => 'Light'], ['bg' => '#09090b', 'body' => $cream, 'lead' => '#a1a1aa', 'label' => 'Dark']] as $surface)
        <div style="border-radius:0.5rem;border:1px solid rgba(113,113,122,0.3);background:{{ $surface['bg'] }};padding:1.25rem;">
            <div style="display:flex;align-items:center;gap:0.625rem;">
                <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" style="height:2.5rem;width:2.5rem;flex-shrink:0;">
                    <rect x="8.5" y="6" width="9.5" height="88" rx="1.2" fill="{{ $surface['body'] }}" />
                    <polygon points="18,13 93,39 18,65" fill="{{ $surface['body'] }}" />
                    <rect x="24" y="30" width="28" height="5.5" fill="{{ $lager }}" />
                    <rect x="24" y="41.5" width="44" height="5.5" fill="{{ $lager }}" />
                </svg>

                <span style="display:flex;flex-direction:column;align-items:flex-start;line-height:1;">
                    <span style="font-size:0.5625rem;font-weight:600;letter-spacing:0.3em;text-transform:uppercase;color:{{ $surface['lead'] }};white-space:nowrap;">{{ $lead }}</span>
                    <span style="font-size:1.25rem;font-weight:800;letter-spacing:-0.025em;color:{{ $surface['body'] }};white-space:nowrap;">{{ $main }}</span>
                </span>
            </div>

            <div style="margin-top:0.875rem;display:flex;align-items:center;gap:0.5rem;">
                <span style="display:inline-block;height:1.25rem;width:1.25rem;border-radius:0.25rem;background:{{ $lager }};"></span>
                <span style="font-size:0.6875rem;color:{{ $surface['lead'] }};">{{ $surface['label'] }} — accent {{ $lager }}</span>
            </div>
        </div>
    @endforeach
</div>
