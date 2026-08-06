@props([
    'placeholder' => 'Search…',
    /** array<string, string> value => label. Omit to render no sort control. */
    'sorts' => [],
    'sort' => '',
    'keyPrefix' => 'sort',
])

{{--
    The one-row control cluster: search owns the row, and everything beside it
    is a compact text button so it reads as a qualifier on the search rather
    than a second field. Filter menus (position, scope) go in the default
    slot; a season select goes in `actions`, far right — WHEN always sits at
    the end of its row.
--}}
<div class="flex items-center gap-3">
    <flux:input
        wire:model.live.debounce.250ms="q"
        size="sm"
        icon="magnifying-glass"
        :placeholder="$placeholder"
        class="min-w-0 flex-1"
    />

    {{ $slot }}

    @if ($sorts !== [])
        {{-- Icon-only: the labels are a word each and the menu carries them,
             so spending row width on "Sort by Last" would take it from the
             search field. A Bootstrap icon passed as a CHILD, never through
             `icon="..."` — that prop resolves against Flux's own set and falls
             back silently to a stroked Heroicon. --}}
        <flux:dropdown position="bottom" align="end" class="shrink-0">
            <button
                type="button"
                aria-label="Sort by {{ $sorts[$sort] ?? '' }}"
                class="flex items-center rounded p-1 text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100"
            >
                <flux:icon.sort-down variant="mini" />
            </button>

            <flux:menu>
                @foreach ($sorts as $value => $label)
                    <flux:menu.item
                        wire:click="$set('sort', '{{ $value }}')"
                        wire:key="{{ $keyPrefix }}-{{ $value }}"
                        @class(['font-semibold' => $sort === $value])
                    >{{ $label }}</flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @endif

    @if ($actions ?? false)
        {{ $actions }}
    @endif
</div>
