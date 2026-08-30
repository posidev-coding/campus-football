{{--
    The hand-off modal's body: the block, and a button that copies it whole.

    The copy is client-side off an already-rendered string — the round trip
    already happened when the modal mounted, and a clipboard write needs the
    user's own gesture anyway.
--}}
<div class="space-y-3">
    <x-filament::button
        x-data=""
        x-on:click="navigator.clipboard?.writeText(@js($handoff))"
        icon="heroicon-m-clipboard-document"
        color="gray"
    >
        Copy the hand-off
    </x-filament::button>

    <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-950/5 p-3 font-mono text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $handoff }}</pre>
</div>
