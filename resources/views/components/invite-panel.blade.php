{{--
    THE INVITE PANEL — one home for the invite idiom: the link that
    travels, copy, the OS share sheet, and the spoken-word code beneath.
    Rooms never render this in any wearing: rooms are joined from the
    lobby, never by invitation (docs/screens.md).

    Two wearings:
    `panel` — the clubhouse Standings tab's collapsible, server-rendered
    open while the group is small (the invite IS the acquisition surface).
    `moment` — the creation wizard's climax: always open, centered, the
    code writ large.

    Collapsed content is x-show, not removed — a test asserts the link
    and code are in the DOM without driving the disclosure.
--}}
@props([
    'url',
    /** The spoken-word fallback for a friend across the room. */
    'code' => null,
    /** The share sheet's title — the group's name. */
    'title' => '',
    'shareText' => '',
    /** panel only: one line under the heading. */
    'hint' => null,
    'variant' => 'panel',
    /** panel only: the server-rendered initial disclosure state. */
    'open' => false,
])

<div
    x-data="{
        open: @js($variant === 'moment' || $open),
        copiedLink: false,
        copiedCode: false,
        canShare: typeof navigator.share === 'function',
        copyLink() {
            window.cfbClipboard.copy(@js($url)).then((ok) => {
                if (! ok) return;

                this.copiedLink = true;
                setTimeout(() => this.copiedLink = false, 2000);
            });
        },
        copyCode() {
            window.cfbClipboard.copy(@js($code)).then((ok) => {
                if (! ok) return;

                this.copiedCode = true;
                setTimeout(() => this.copiedCode = false, 2000);
            });
        },
        share() {
            navigator.share({
                title: @js($title),
                text: @js($shareText),
                url: @js($url),
            }).catch(() => {});
        },
    }"
    {{ $attributes->class(['overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700']) }}
>
    @if ($variant === 'panel')
        <button
            type="button"
            x-on:click="open = ! open"
            aria-expanded="{{ $open ? 'true' : 'false' }}"
            x-bind:aria-expanded="open"
            aria-controls="invite-panel-{{ $code ?? 'link' }}"
            class="focus-ring flex w-full items-center gap-3 p-4 text-start"
        >
            <span class="min-w-0 flex-1">
                <span class="block font-bold leading-tight">Invite</span>
                @if ($hint)
                    <span class="block truncate pt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $hint }}</span>
                @endif
            </span>
            <flux:icon name="chevron-down" variant="micro" class="shrink-0 text-zinc-400 transition-transform" x-bind:class="open && 'rotate-180'" />
        </button>

        <div
            id="invite-panel-{{ $code ?? 'link' }}"
            x-show="open"
            x-cloak
            class="flex flex-col gap-2 border-t border-zinc-100 px-4 py-3 dark:border-zinc-800/60"
        >
            <div class="flex flex-wrap items-center gap-2">
                <span class="max-w-full truncate font-mono text-sm font-semibold">{{ Str::after($url, '://') }}</span>
                <flux:button x-on:click="copyLink()" size="sm" variant="primary">
                    <span x-show="! copiedLink">Copy link</span>
                    <span x-show="copiedLink" x-cloak>Copied</span>
                </flux:button>
                <flux:button x-show="canShare" x-cloak x-on:click="share()" size="sm">
                    <flux:icon.box-arrow-up variant="micro" />
                    Share
                </flux:button>
            </div>

            @if ($code !== null)
                <div class="flex items-center gap-3 border-t border-zinc-100 pt-2 dark:border-zinc-800/60">
                    <p class="text-micro text-zinc-400">Or read them the code</p>
                    <span class="font-mono text-lg font-bold tracking-widest">{{ $code }}</span>
                    <flux:button x-on:click="copyCode()" size="xs" variant="ghost">
                        <span x-show="! copiedCode">Copy</span>
                        <span x-show="copiedCode" x-cloak>Copied</span>
                    </flux:button>
                </div>
            @endif
        </div>
    @else
        <div class="flex flex-col items-center gap-3 px-4 py-6">
            <p class="text-micro font-medium uppercase tracking-wide text-zinc-400">Invite link</p>
            <p class="max-w-full truncate font-mono text-sm font-semibold">{{ Str::after($url, '://') }}</p>

            <div class="flex items-center gap-2">
                <flux:button x-on:click="copyLink()" size="sm" variant="primary">
                    <span x-show="! copiedLink">Copy link</span>
                    <span x-show="copiedLink" x-cloak>Copied</span>
                </flux:button>

                <flux:button x-show="canShare" x-cloak x-on:click="share()" size="sm">
                    <flux:icon.box-arrow-up variant="micro" />
                    Share
                </flux:button>
            </div>

            @if ($code !== null)
                <div class="flex flex-col items-center gap-1 border-t border-zinc-100 pt-3 dark:border-zinc-800/60">
                    <p class="text-micro text-zinc-400">Or read them the code</p>
                    <p class="font-mono text-2xl font-bold tracking-[0.3em]">{{ $code }}</p>
                </div>
            @endif
        </div>
    @endif
</div>
