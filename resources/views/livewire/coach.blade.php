<?php

use App\Models\Coach;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A coach page: who they are, where they coach, and where they have been.
 *
 * Routed by id, matching athletes — coaches have no slug, and the table grows
 * as historical staffs sync. The tenure list is the point of the page: a
 * coach's story is the schools, in order.
 */
new class extends Component
{
    public Coach $coach;

    public function mount(Coach $coach): void
    {
        $this->coach = $coach;
    }

    /** Newest first — the current job is the one a reader came to check. */
    #[Computed]
    public function tenures()
    {
        return $this->coach->seasons()
            ->with('team:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark')
            ->orderByDesc('season_year')
            ->get();
    }
}; ?>

<div class="flex flex-col gap-5">
    <div class="flex items-start gap-4">
        @if ($coach->headshot_url)
            <img src="{{ $coach->headshot_url }}" alt=""
                 class="size-20 shrink-0 rounded-full bg-zinc-100 object-cover dark:bg-zinc-800">
        @else
            <div class="flex size-20 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-lg font-semibold text-zinc-400 dark:bg-zinc-800">
                {{ str($coach->display_name)->substr(0, 1) }}
            </div>
        @endif

        <div class="flex min-w-0 flex-col gap-1">
            <flux:heading size="xl" class="truncate">{{ $coach->display_name }}</flux:heading>

            @if ($this->tenures->first()?->team)
                <x-team-link :team="$this->tenures->first()->team" class="text-zinc-600 dark:text-zinc-400" />
            @endif

            <div class="flex flex-wrap gap-1.5 pt-0.5">
                @foreach (collect([
                    'Head Coach',
                    $coach->careerRecord() ? $coach->careerRecord().' career' : null,
                    $coach->hometown(),
                ])->filter() as $fact)
                    <flux:badge size="sm" color="zinc">{{ $fact }}</flux:badge>
                @endforeach
            </div>
        </div>
    </div>

    @if ($this->tenures->isNotEmpty())
        <div class="flex flex-col gap-2">
            <flux:subheading>Seasons</flux:subheading>

            <div class="flex flex-col divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                @foreach ($this->tenures as $tenure)
                    <div class="flex items-center gap-3 px-3 py-2" wire:key="tenure-{{ $tenure->id }}">
                        <span class="tabular w-12 shrink-0 text-sm text-zinc-500">{{ $tenure->season_year }}</span>

                        @if ($tenure->team)
                            <x-team-link :team="$tenure->team" class="min-w-0 flex-1" />
                        @else
                            <span class="flex-1 text-sm text-zinc-400">—</span>
                        @endif

                        @if ($tenure->record())
                            <span class="tabular shrink-0 text-sm text-zinc-600 dark:text-zinc-300">{{ $tenure->record() }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <flux:callout icon="academic-cap">
            <flux:callout.heading>No seasons on file</flux:callout.heading>
            <flux:callout.text>
                Season-by-season history fills in as the coach sync runs.
            </flux:callout.text>
        </flux:callout>
    @endif
</div>
