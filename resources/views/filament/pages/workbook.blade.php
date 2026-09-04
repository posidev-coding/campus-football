{{--
    The board. Columns are statuses; a card drags within a column to reorder
    and across columns to change status.

    THREE ATTRIBUTES CARRY THE WHOLE DRAG, and two of them are not obvious:

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

    THE DRAG ONLY EXISTS ON THE NATURAL BOARD. Narrow or group it and the sort
    attributes are withheld (and `move()` refuses server-side): a filtered
    column hides cards, so Sortable's index counts the visible ones while
    positions are measured against the whole column, and a grouped column is
    not in position order at all. The card markup itself lives in
    partials/workbook-card so both renderings are one file.

    No interaction test can reach any of this: SortableJS ignores synthetic
    pointer events. WorkbookBoardTest asserts the rendered attributes and
    proves the outcome through MoveWorkbookItem.

    "c" FILES A CARD, and it is hand-rolled on purpose. Filament's own
    `->keyBindings(['c'])` renders `x-mousetrap.global`, and `bindGlobal` is
    documented to fire INSIDE inputs — exactly right for the cmd+k it was built
    for, and wrong for a bare letter, which would open this modal on the "c" of
    "coverage" typed into the panel's global search. So the guard is explicit
    and there are four of them: a modifier held (Cmd+C is a copy), an editable
    target, a modal already open, and a repeat from a held-down key.

    "A MODAL IS OPEN" IS A DOM QUESTION, NOT A LIVEWIRE ONE. `$wire.mountedActions`
    is the obvious guard and it is the wrong one: Escape closes the modal in
    Alpine and the property goes on naming the action it just closed until the
    next round trip clears it — measured at over a second on a card's view
    modal, which is long enough to swallow the keystroke that follows. The
    framework's own mousetrap directive asks the DOM instead, for a VISIBLE
    `[aria-modal=true]`, so this does too. The selector's value is unquoted
    because the attribute around it is not.

    The body lives in an `x-data` METHOD because a multi-statement Alpine
    expression that does not start with `let`/`const` is a SyntaxError Alpine
    swallows — the directive would not break, it would be INERT.
--}}
<x-filament-panels::page
    x-data="{
        file(event) {
            if (event.repeat || event.metaKey || event.ctrlKey || event.altKey) return
            const target = event.target
            if (target?.isContentEditable) return
            if (['INPUT', 'SELECT', 'TEXTAREA'].includes(target?.tagName)) return
            const modals = Array.from(document.querySelectorAll('[aria-modal=true]'))
            if (modals.some(modal => getComputedStyle(modal).display !== 'none')) return
            event.preventDefault()
            $wire.mountAction('file')
        },
    }"
    x-on:keydown.c.window="file($event)"
>
    {{-- The read controls. Selects rather than pill rows because five
         vocabularies side by side is a toolbar, not a screen — and the panel
         is the one place a native select is house style. --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <x-filament::input.wrapper class="w-40">
            <x-filament::input.select wire:model.live="severity">
                <option value="">All severities</option>
                @foreach (\App\Enums\WorkbookSeverity::options() as $value => $optionLabel)
                    <option value="{{ $value }}">{{ $optionLabel }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>

        <x-filament::input.wrapper class="w-40">
            <x-filament::input.select wire:model.live="category">
                <option value="">All categories</option>
                @foreach (\App\Enums\WorkbookCategory::options() as $value => $optionLabel)
                    <option value="{{ $value }}">{{ $optionLabel }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>

        <x-filament::input.wrapper class="w-36">
            <x-filament::input.select wire:model.live="effort">
                <option value="">Any effort</option>
                {{-- The board speaks effort in the card's own S/M/L, never
                     the full words — `Large` beside `High` is the collision
                     the enum's short() exists to avoid, and a sweep holds
                     the word off this page entirely. --}}
                @foreach (\App\Enums\WorkbookEffort::cases() as $case)
                    <option value="{{ $case->value }}">Effort {{ $case->short() }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>

        @if ($this->labelOptions !== [])
            <x-filament::input.wrapper class="w-40">
                <x-filament::input.select wire:model.live="label">
                    <option value="">All labels</option>
                    @foreach ($this->labelOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        @endif

        <x-filament::input.wrapper class="w-44">
            <x-filament::input.select wire:model.live="group">
                <option value="">No grouping</option>
                <option value="severity">Group by severity</option>
                <option value="category">Group by category</option>
                <option value="effort">Group by effort</option>
            </x-filament::input.select>
        </x-filament::input.wrapper>

        @unless ($this->sortable)
            <button
                type="button"
                wire:click="clearControls"
                class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
            >
                Clear
            </button>
            {{-- Said out loud, because a handle that silently vanished reads
                 as the drag breaking rather than a rule. --}}
            <span class="text-xs text-gray-400 dark:text-gray-500">
                Drag is paused while the board is narrowed or grouped.
            </span>
        @endunless
    </div>

    <div class="flex gap-4 overflow-x-auto pb-4">
        @foreach ($this->columns as $column)
            {{-- w-72, not w-80: five columns, and the fifth one has to be
                 reachable without the board feeling like a corridor. --}}
            <section class="w-72 shrink-0 rounded-xl bg-gray-100 p-3 dark:bg-white/5">
                <header class="mb-3 flex items-center justify-between px-1">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                        {{ $column['status']->label() }}
                    </h2>
                    <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400">
                        {{ $column['items']->count() }}
                    </span>
                </header>

                <div
                    @if ($this->sortable)
                        wire:sort="move"
                        wire:sort:group-id="{{ $column['status']->value }}"
                        x-sort:group="workbook"
                    @endif
                    class="flex min-h-24 flex-col gap-2"
                >
                    @foreach ($column['groups'] as $subgroup)
                        @if ($subgroup['label'] !== null)
                            <h3
                                wire:key="workbook-{{ $column['status']->value }}-{{ Str::slug($subgroup['label']) }}"
                                class="mt-2 flex items-center justify-between px-1 text-xs font-semibold uppercase tracking-wide text-gray-400 first:mt-0 dark:text-gray-500"
                            >
                                {{ $subgroup['label'] }}
                                <span class="font-mono normal-case tracking-normal">{{ $subgroup['items']->count() }}</span>
                            </h3>
                        @endif

                        @foreach ($subgroup['items'] as $item)
                            @include('filament.pages.partials.workbook-card', [
                                'item' => $item,
                                'sortable' => $this->sortable,
                            ])
                        @endforeach
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
