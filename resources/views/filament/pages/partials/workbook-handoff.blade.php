@php
    /*
     * The click handler is composed HERE, in PHP, and passed as a BOUND
     * attribute — never written as `@js($handoff)` inside the component tag.
     *
     * Blade compiles directives in ordinary template text, so `@js()` in a
     * plain <button x-on:click="…"> works (the card partial relies on it). A
     * COMPONENT TAG's static attribute value is not template text: the tag
     * compiler captures it verbatim into the attribute bag, so the directive
     * ships to the browser as the literal string `@js($handoff)`. Alpine then
     * fails to parse it and the button is INERT — no console error, no
     * exception, nothing at the layer anybody is looking at. Same family as
     * "an Alpine expression that starts with a comment never runs".
     *
     * `Js::from` is the same encoder `@js` uses, and it is what the board
     * page's own header action already does for exactly this reason.
     */
    $copy = 'navigator.clipboard?.writeText('.\Illuminate\Support\Js::from($handoff).')';
@endphp

<div class="space-y-3">
    {{-- The copy is client-side off an already-rendered string — the round
         trip happened when the modal mounted, and a clipboard write needs the
         user's own gesture anyway. --}}
    <x-filament::button
        x-data=""
        :x-on:click="$copy"
        icon="heroicon-m-clipboard-document"
        color="gray"
    >
        Copy the hand-off
    </x-filament::button>

    <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-950/5 p-3 font-mono text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $handoff }}</pre>
</div>
