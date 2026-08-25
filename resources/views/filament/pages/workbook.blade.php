{{--
    The board. Columns are statuses; a card drags within a column to reorder
    and across columns to change status.

    THREE ATTRIBUTES CARRY THE WHOLE FEATURE, and two of them are not obvious:

    - `wire:sort="move"` is a BARE METHOD NAME. Never `move($item, $position)`
      — Livewire rewrites those magics to `$wire.$item`/`$wire.$position`,
      both undefined, and the server is handed nulls.
    - `wire:sort:group-id` is what makes this a Kanban rather than four
      independent lists. Livewire appends it to the handler's arguments, and
      Sortable fires the handler on the DESTINATION list, so it arrives as
      "the column this landed in".
    - `x-sort:group`, NOT `wire:sort:group`. Both name the Sortable group, but
      Livewire's attribute loop `return`s on `wire:sort:group` — so if it were
      to sit before `wire:sort` in the source, `wire:sort` would never bind and
      the drag would silently do nothing. Alpine's own attribute is read by the
      same `getGroupName()` and is not in that loop at all.

    No interaction test can reach any of this: SortableJS ignores synthetic
    pointer events. WorkbookBoardTest asserts the rendered attributes and
    proves the outcome through MoveWorkbookItem.
--}}
<x-filament-panels::page>
    <div class="flex gap-4 overflow-x-auto pb-4">
        @foreach ($this->columns as $column)
            <section class="w-80 shrink-0 rounded-xl bg-gray-100 p-3 dark:bg-white/5">
                <header class="mb-3 flex items-center justify-between px-1">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                        {{ $column['status']->label() }}
                    </h2>
                    <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400">
                        {{ $column['items']->count() }}
                    </span>
                </header>

                <div
                    wire:sort="move"
                    wire:sort:group-id="{{ $column['status']->value }}"
                    x-sort:group="workbook"
                    class="flex min-h-24 flex-col gap-2"
                >
                    @foreach ($column['items'] as $item)
                        <article
                            wire:sort:item="{{ $item->id }}"
                            wire:key="workbook-{{ $item->id }}"
                            class="rounded-lg bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                        >
                            <div class="flex items-start gap-2">
                                {{-- The handle makes the CARD draggable without
                                     swallowing a tap meant for something inside
                                     it. Its presence is what turns handle mode
                                     on — Alpine detects it, no modifier needed. --}}
                                <span
                                    wire:sort:handle
                                    class="mt-0.5 shrink-0 cursor-grab touch-none text-gray-300 active:cursor-grabbing dark:text-gray-600"
                                    aria-hidden="true"
                                >
                                    <x-filament::icon icon="heroicon-m-bars-2" class="h-4 w-4" />
                                </span>

                                <p class="min-w-0 flex-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ $item->title }}
                                </p>
                            </div>

                            <div class="mt-2 flex flex-wrap items-center gap-1.5 pl-6">
                                <x-filament::badge :color="$item->category->color()" size="xs">
                                    {{ $item->category->label() }}
                                </x-filament::badge>
                                <x-filament::badge :color="$item->severity->color()" size="xs">
                                    {{ $item->severity->label() }}
                                </x-filament::badge>
                                @if ($item->first_seen_at)
                                    {{-- How long this has been true, which a
                                         re-propose never resets. --}}
                                    <span class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ $item->first_seen_at->diffForHumans(short: true) }}
                                    </span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
