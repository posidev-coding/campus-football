{{--
    The appearance control, shared by its two homes: the Account screen's
    sticky header and the desktop avatar menu. One partial so the two can never
    drift — and so ChromeConsistencyTest's `variant="segmented"` allowlist can
    point at exactly one file.

    Icon-only. The three labels were the widest thing in the card and said less
    than the icons do.

    `$flux.appearance` is Flux's own store — it writes the `.dark` class on
    <html>, persists to localStorage, and keeps listening to the OS preference
    after load so "System" keeps tracking rather than freezing at whatever it
    was when the page rendered. Two controls, one localStorage truth: they can
    never disagree.

    A RADIO group rather than a row of buttons is load-bearing inside the
    avatar menu: flux:menu auto-promotes every plain <a>/<button> descendant
    into a menuitem that closes the popover on click, but its walker skips
    <ui-radio> — so the dropdown stays open while the reader tries all three.
--}}
<flux:radio.group x-data variant="segmented" size="sm" x-model="$flux.appearance" {{ $attributes }}>
    <flux:radio value="light" icon="sun" aria-label="Light" />
    <flux:radio value="dark" icon="moon" aria-label="Dark" />
    <flux:radio value="system" icon="computer-desktop" aria-label="Match system" />
</flux:radio.group>
