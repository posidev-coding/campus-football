{{--
    One card, wherever a card appears — the flat sortable column and every
    grouped bucket render exactly this. Receives `item` and `sortable`.

    The sort attributes and the handle come and go TOGETHER with `sortable`:
    a grab cursor on a card that will not move is the board lying about
    itself, which is worse than the affordance disappearing.
--}}
<article
    @if ($sortable)
        wire:sort:item="{{ $item->id }}"
    @endif
    wire:key="workbook-{{ $item->id }}"
    class="rounded-lg bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
>
    <div class="flex items-start gap-2">
        @if ($sortable)
            {{-- The handle makes the CARD draggable without swallowing a tap
                 meant for something inside it. Its presence is what turns
                 handle mode on — Alpine detects it, no modifier needed. --}}
            <span
                wire:sort:handle
                class="mt-0.5 shrink-0 cursor-grab touch-none text-gray-300 active:cursor-grabbing dark:text-gray-600"
                aria-hidden="true"
            >
                <x-filament::icon icon="heroicon-m-bars-2" class="h-4 w-4" />
            </span>
        @endif

        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5">
                <span class="font-mono text-[11px] text-gray-400 dark:text-gray-500">
                    {{ $item->reference }}
                </span>
                @if ($item->effort)
                    {{-- Effort is a MARKER here, not a badge, and that is the
                         whole point. A card has no column header, so a `Large`
                         badge sat beside a `High` badge in the same amber, and
                         `Medium` collided with `Medium` on both the word and
                         the color. It stays a badge on the table and the
                         infolist, where a labelled column says which is which. --}}
                    <span
                        class="font-mono text-[11px] text-gray-400 dark:text-gray-500"
                        data-effort="{{ $item->effort->value }}"
                    >&middot; {{ $item->effort->short() }}</span>
                @endif
                @if ($item->isBlocked())
                    {{-- Blocked by something nobody has finished. A session
                         reads this and stops. --}}
                    <span class="text-xs text-danger-600 dark:text-danger-400" title="Blocked">
                        <x-filament::icon icon="heroicon-m-lock-closed" class="h-3 w-3" />
                    </span>
                @endif
            </div>
            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                {{ $item->title }}
            </p>
        </div>

        @if (in_array($item->status, [\App\Enums\WorkbookStatus::Inbox, \App\Enums\WorkbookStatus::Planned], true))
            {{-- `cfb:issue start`, from the card. Mounts the page's confirmed
                 action — a claim, a branch and a trail row are not what a
                 misclick should write. --}}
            <button
                type="button"
                wire:click.stop="mountAction('start', { item: {{ $item->id }} })"
                class="shrink-0 text-gray-300 hover:text-primary-600 dark:text-gray-600 dark:hover:text-primary-400"
                title="Start {{ $item->reference }}"
            >
                <x-filament::icon icon="heroicon-m-play" class="h-4 w-4" />
            </button>
        @endif

        @if ($item->branch === null)
            {{-- Filament's own copyable() is a table and infolist concern, so
                 the card gets four lines of Alpine instead.

                 `.stop` matters: the card is handle-dragged today, so a stray
                 click cannot start a drag — and stopping propagation keeps
                 that true if handle mode ever comes off. --}}
            <button
                type="button"
                x-data
                x-on:click.stop="navigator.clipboard?.writeText(@js('/work '.$item->reference))"
                class="shrink-0 text-gray-300 hover:text-gray-500 dark:text-gray-600 dark:hover:text-gray-400"
                title="Copy /work {{ $item->reference }}"
            >
                <x-filament::icon icon="heroicon-m-clipboard-document" class="h-4 w-4" />
            </button>
        @else
            {{-- Started, so there is a whole brief to hand over. A modal
                 rather than an inline copy: the hand-off block costs queries
                 (trail, links) that must not run once per card on render. --}}
            <button
                type="button"
                wire:click.stop="mountAction('handoff', { item: {{ $item->id }} })"
                class="shrink-0 text-gray-300 hover:text-gray-500 dark:text-gray-600 dark:hover:text-gray-400"
                title="Session hand-off for {{ $item->reference }}"
            >
                <x-filament::icon icon="heroicon-m-clipboard-document-check" class="h-4 w-4" />
            </button>
        @endif
    </div>

    <div class="mt-2 flex flex-wrap items-center gap-1.5 {{ $sortable ? 'pl-6' : '' }}">
        <x-filament::badge :color="$item->category->color()" size="xs">
            {{ $item->category->label() }}
        </x-filament::badge>
        <x-filament::badge :color="$item->severity->color()" size="xs">
            {{ $item->severity->label() }}
        </x-filament::badge>
        @foreach ($item->labels ?? [] as $label)
            <x-filament::badge color="gray" size="xs">{{ $label }}</x-filament::badge>
        @endforeach
        @if ($item->first_seen_at)
            {{-- How long this has been true, which a re-propose never resets. --}}
            <span class="text-xs text-gray-400 dark:text-gray-500">
                {{ $item->first_seen_at->diffForHumans(short: true) }}
            </span>
        @endif
    </div>
</article>
