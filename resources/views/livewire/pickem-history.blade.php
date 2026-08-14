<?php

use App\Models\SlateEntry;
use App\Models\Slate;
use App\Support\Voice;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * YOUR WEEKS — every settled slate you entered, newest first: the room or
 * group, the game it played, your points, your place in the field, and
 * the W where you took the week. Each row links back to the room it
 * happened in, which is why a public room's URL outlives its week.
 *
 * This screen's prime moment is Sunday and Monday — exactly when the
 * lobby's inventory is emptiest, which is how History earned its strip
 * slot.
 */
new class extends Component
{
    /** @return Collection<int, SlateEntry> newest settled week first */
    #[Computed]
    public function entries(): Collection
    {
        return SlateEntry::query()
            ->join('slates', 'slates.id', '=', 'slate_entries.slate_id')
            ->where('slate_entries.user_id', auth()->id())
            ->where('slates.status', Slate::SETTLED)
            ->orderByDesc('slates.settled_at')
            ->select('slate_entries.*')
            ->with([
                'slate.week:id,number,season_id',
                'slate.contest:id,group_id,mode,season_year',
                'slate.contest.group:id,name,kind,week_id',
            ])
            ->get();
    }

    /**
     * My place in each week's field — "3rd of 9" — from ONE query across
     * all the slates on this screen, never one per row.
     *
     * @return array<int, array{place: int, of: int}> keyed by slate id
     */
    #[Computed]
    public function places(): array
    {
        $slateIds = $this->entries->pluck('slate_id');

        if ($slateIds->isEmpty()) {
            return [];
        }

        return SlateEntry::query()
            ->whereIn('slate_id', $slateIds)
            ->get(['slate_id', 'user_id', 'final_points'])
            ->groupBy('slate_id')
            ->map(function (Collection $field) {
                $ranked = $field->sortByDesc(fn (SlateEntry $entry) => $entry->final_points ?? 0)->values();
                $mine = $ranked->search(fn (SlateEntry $entry) => $entry->user_id === auth()->id());

                return [
                    'place' => $mine === false ? $ranked->count() : $mine + 1,
                    'of' => $ranked->count(),
                ];
            })
            ->all();
    }

    /** @return array{weeks: int, wins: int, best: int} */
    #[Computed]
    public function summary(): array
    {
        return [
            'weeks' => $this->entries->count(),
            'wins' => $this->entries->where('won', true)->count(),
            'best' => (int) $this->entries->max('final_points'),
        ];
    }
}; ?>

<div class="flex flex-col gap-4 lg:mx-auto lg:w-full lg:max-w-2xl">
    <h1 class="sr-only">History</h1>

    @if ($this->entries->isNotEmpty())
        {{-- The season so far, in three numbers. --}}
        <div class="flex items-center gap-2">
            <span class="tabular rounded-full bg-zinc-100 px-3 py-1 text-sm font-semibold dark:bg-zinc-800">{{ $this->summary['weeks'] }} {{ Str::plural('week', $this->summary['weeks']) }}</span>
            <span class="tabular rounded-full bg-zinc-100 px-3 py-1 text-sm font-semibold dark:bg-zinc-800">{{ $this->summary['wins'] }} {{ Str::plural('win', $this->summary['wins']) }}</span>
            <span class="tabular rounded-full bg-zinc-100 px-3 py-1 text-sm font-semibold dark:bg-zinc-800">best {{ $this->summary['best'] }} pts</span>
        </div>

        @foreach ($this->entries->groupBy(fn ($entry) => $entry->slate->week_id) as $weekEntries)
            @php $week = $weekEntries->first()->slate->week; @endphp

            <div wire:key="history-week-{{ $week->id }}" class="flex flex-col gap-2">
                <flux:subheading
                    class="sticky z-20 -mx-4 bg-white px-4 py-1.5 dark:bg-zinc-950 top-[env(safe-area-inset-top)] sm:top-[var(--header-offset)]"
                >
                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">Week {{ $week->number }}</span>
                    <span class="text-micro text-zinc-400">· {{ $weekEntries->first()->slate->contest->season_year }}</span>
                </flux:subheading>

                @foreach ($weekEntries as $entry)
                    @php
                        $group = $entry->slate->contest->group;
                        $place = $this->places[$entry->slate_id] ?? null;
                    @endphp

                    <a
                        href="{{ $group->isRoom() ? route('pickem.room', $group) : route('pickem.group', $group) }}"
                        wire:navigate
                        wire:key="history-{{ $entry->id }}"
                        class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-2.5 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $group->name }}</p>
                            <p class="text-micro text-zinc-500 dark:text-zinc-400">{{ $entry->slate->contest->mode->label() }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2 text-sm">
                            @if ($entry->won)
                                <flux:badge size="sm" color="green">Winner</flux:badge>
                            @elseif ($place !== null)
                                <span class="tabular text-micro text-zinc-500">{{ Number::ordinal($place['place']) }} of {{ $place['of'] }}</span>
                            @endif
                            <span class="tabular font-semibold">{{ $entry->final_points ?? 0 }} pts</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endforeach
    @else
        <p class="rounded-xl border border-dashed border-zinc-300 px-4 py-3 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            {{ Voice::line('history.empty') }}
        </p>
    @endif
</div>
