{{--
    Gameday — the MLB sheet of the same name, recreated. A bottom sheet over
    a scrim, one vertical list of that ET day's games grouped by what the
    viewer cares about: their teams, ranked matchups, this game's
    conference(s), the rest. Each game claimed once, by the first group that
    wants it — the scoreboard's floated-block rule.

    Mechanics that are load-bearing:
      - Rendered at the PAGE ROOT, never inside the sticky scorebug — its
        backdrop-blur is a containing block for fixed descendants and would
        cap this sheet at the scorebug's own size (the search-panel lesson).
      - z-50 over a z-40 scrim, above app chrome at 40.
      - Drag-to-dismiss and the entrance spring run through element.animate,
        which leaves no inline style for a Livewire morph to strand; multi-
        statement bodies live in x-data METHODS, never in x-init.
      - x-trap.noscroll traps focus and locks body scroll in one move;
        prefers-reduced-motion short-circuits every tween.
      - A vertical list: the no-horizontal-scroll rule has exactly three
        exceptions and this is not becoming a fourth.
--}}
@if ($sheetOpen)
    <div
        wire:key="league-sheet"
        x-data="{
            startY: null,
            delta: 0,
            reduced() { return window.matchMedia('(prefers-reduced-motion: reduce)').matches },
            enter() {
                if (this.reduced()) return;
                this.$refs.panel.animate(
                    [{ transform: 'translateY(100%)' }, { transform: 'translateY(0)' }],
                    { duration: 320, easing: 'cubic-bezier(0.32, 0.72, 0, 1)' },
                );
            },
            down(event) { this.startY = event.clientY },
            move(event) {
                if (this.startY !== null) this.delta = Math.max(0, event.clientY - this.startY);
            },
            up() {
                if (this.startY === null) return;
                const d = this.delta;
                this.startY = null;
                d > 90 ? this.close() : this.settle(d);
            },
            settle(from) {
                this.delta = 0;
                if (from > 0 && ! this.reduced()) this.$refs.panel.animate(
                    [{ transform: `translateY(${from}px)` }, { transform: 'translateY(0)' }],
                    { duration: 200, easing: 'ease-out' },
                );
            },
            close() {
                const from = this.delta;
                this.delta = 0;
                if (this.reduced()) return this.$wire.set('sheetOpen', false);
                this.$refs.panel
                    .animate(
                        [{ transform: `translateY(${from}px)` }, { transform: 'translateY(110%)' }],
                        { duration: 220, easing: 'ease-in' },
                    )
                    .finished.then(() => this.$wire.set('sheetOpen', false));
            },
        }"
        x-on:keydown.escape.window="close()"
        x-on:pointermove.window="move($event)"
        x-on:pointerup.window="up()"
    >
        {{-- Scrim: z-40 beats the tab bar it dims. --}}
        <div class="fixed inset-0 z-40 bg-black/40" x-on:click="close()" aria-hidden="true"></div>

        <div
            x-ref="panel"
            x-init="enter()"
            x-trap.noscroll="true"
            :style="startY !== null ? `transform: translateY(${delta}px)` : ''"
            {{-- A FIXED height, not a max: paging the date changes how many
                 games there are, and a sheet that resizes under the pager
                 makes the arrows move while you are tapping them. The list
                 inside takes the remaining space and scrolls, so a light
                 Tuesday and a 60-game Saturday sit in the same box. --}}
            class="fixed inset-x-0 bottom-0 z-50 flex h-[85dvh] flex-col rounded-t-2xl bg-white shadow-2xl dark:bg-zinc-900"
            role="dialog"
            aria-modal="true"
            aria-label="Gameday"
        >
            {{-- The grab handle is the drag surface; the list below scrolls. --}}
            <div class="shrink-0 cursor-grab touch-none select-none" x-on:pointerdown="down($event)">
                <div class="mx-auto mt-2 h-1 w-9 rounded-full bg-zinc-300 dark:bg-zinc-600"></div>

                {{-- Title centered with the close control floated over it, the
                     way the MLB sheet does — the heading names the sheet, and
                     centring it stops the X reading as part of the title. --}}
                <div class="relative flex items-center justify-center px-4 py-2.5">
                    <h2 class="text-sm font-semibold">Gameday</h2>

                    <button type="button" x-on:click="close()" class="absolute end-2 rounded-md p-1 text-zinc-400 transition-colors hover:text-zinc-700 dark:hover:text-zinc-200" aria-label="Close">
                        <flux:icon.x-mark variant="mini" />
                    </button>
                </div>

                <div class="flex items-center justify-between border-y border-zinc-100 px-2 py-1.5 dark:border-zinc-800">
                    <button type="button" wire:click="shiftLeagueDay(-1)" class="rounded-md p-1.5 text-zinc-400 transition-colors hover:text-zinc-700 dark:hover:text-zinc-200" aria-label="Previous day">
                        <flux:icon.chevron-left variant="mini" />
                    </button>

                    <span class="text-stat font-semibold">
                        {{ \Carbon\CarbonImmutable::parse($leagueDate)->format('l, F j') }}
                    </span>

                    <button type="button" wire:click="shiftLeagueDay(1)" class="rounded-md p-1.5 text-zinc-400 transition-colors hover:text-zinc-700 dark:hover:text-zinc-200" aria-label="Next day">
                        <flux:icon.chevron-right variant="mini" />
                    </button>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2 pb-[max(env(safe-area-inset-bottom),0.75rem)]">
                @forelse ($this->leagueSlate as $group)
                    <div wire:key="lg-{{ $group['label'] }}">
                        <h3 class="px-2 pt-3 pb-1 text-micro font-semibold tracking-wide text-zinc-400 uppercase">
                            {{ $group['label'] }}
                        </h3>

                        {{-- Ruled rows, MLB-style: one hairline between games,
                             inset by the list's own padding, and no rounded
                             hover — a row runs the full width of the sheet, so
                             the whole thing is the tap target. --}}
                        <ol class="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($group['games'] as $row)
                                @php
                                    $live = $row->status === 'in';
                                    $winner = $row->winnerTeamId();
                                @endphp

                                <li wire:key="lgg-{{ $row->id }}">
                                    <a href="{{ route('game', $row) }}" class="flex items-center gap-2 px-2 py-3 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                        <span class="flex min-w-0 flex-1 items-center justify-end gap-1.5">
                                            <span class="flex min-w-0 flex-col items-end">
                                                <span @class(['truncate text-stat', 'font-semibold' => ! $row->completed || $winner === $row->away_team_id, 'text-zinc-500' => $row->completed && $winner !== $row->away_team_id])>
                                                    {{ $row->awayTeam?->placeName() ?? 'TBD' }}
                                                </span>
                                                <span class="text-micro text-zinc-400">{{ $row->away_record }}</span>
                                            </span>
                                            <x-team-logo :team="$row->awayTeam" size="md" class="shrink-0" />
                                        </span>

                                        {{-- w-14, not w-20: measured, the widest
                                             this column ever holds is "12:00pm"
                                             over a network at 52px, and the 24px
                                             that buys splits to the two name
                                             columns — which is what keeps
                                             "Eastern Michigan" off the ellipsis
                                             now that the logos are 32px. --}}
                                        <span class="flex w-14 shrink-0 flex-col items-center text-center">
                                            @if ($live)
                                                <span class="tabular text-stat font-bold">{{ $row->away_score }}–{{ $row->home_score }}</span>
                                                <span class="text-micro font-semibold text-red-600 dark:text-red-400">{{ $row->status_detail ?? 'Live' }}</span>
                                            @elseif ($row->completed)
                                                <span class="tabular text-stat font-bold">{{ $row->away_score }}–{{ $row->home_score }}</span>
                                                <span class="text-micro text-zinc-400">Final</span>
                                            @else
                                                <span class="text-stat font-medium">
                                                    {{ $row->kickoffLabel('time') }}
                                                </span>
                                                @if ($row->broadcasts)
                                                    <span class="truncate text-micro text-zinc-400">{{ $row->broadcasts[0] }}</span>
                                                @endif
                                            @endif
                                        </span>

                                        <span class="flex min-w-0 flex-1 items-center gap-1.5">
                                            <x-team-logo :team="$row->homeTeam" size="md" class="shrink-0" />
                                            <span class="flex min-w-0 flex-col">
                                                <span @class(['truncate text-stat', 'font-semibold' => ! $row->completed || $winner === $row->home_team_id, 'text-zinc-500' => $row->completed && $winner !== $row->home_team_id])>
                                                    {{ $row->homeTeam?->placeName() ?? 'TBD' }}
                                                </span>
                                                <span class="text-micro text-zinc-400">{{ $row->home_record }}</span>
                                            </span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @empty
                    {{-- Centered, because a fixed-height sheet would otherwise
                         leave one line of text stranded at the top of a very
                         tall empty box. --}}
                    <div class="flex h-full items-center justify-center">
                        <p class="px-2 text-center text-sm text-zinc-500">Nothing on the slate this day.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
@endif
