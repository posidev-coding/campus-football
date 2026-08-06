<?php

use App\Models\Conference;
use App\Models\Standing;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Services\CfbCalendar;
use App\Support\Scope;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every team, grouped by conference.
 *
 * Grouping reads through team_seasons, so the page shows a season's actual
 * membership rather than today's — Oregon appears under the Pac-12 for 2021 and
 * the Big Ten for 2025.
 */
new class extends Component
{
    #[Url]
    public ?int $year = null;

    /**
     * One WHO filter — 'fbs', 'fcs', or a conference id as a string — the
     * same scope vocabulary as every other League screen. Replaced a
     * classification select, and gained the conference jump this page never
     * had.
     */
    #[Url]
    public string $scope = Scope::FBS;

    #[Url]
    public string $q = '';

    public function mount(CfbCalendar $calendar): void
    {
        $this->year ??= $calendar->resultsYear();

        $this->normaliseScope();
    }

    /**
     * Needed in BOTH places: `#[Url]` hydrates from the querystring without
     * firing the update hook, so a stale `?classification=FCS` bookmark must
     * fall back to the default on first load.
     */
    public function updatedScope(): void
    {
        $this->normaliseScope();
    }

    private function normaliseScope(): void
    {
        if (! in_array($this->scope, [Scope::FBS, Scope::FCS], true) && ! ctype_digit($this->scope)) {
            $this->scope = Scope::FBS;
        }
    }

    /** @return list<int> */
    #[Computed]
    public function years(): array
    {
        return Cache::remember('teams:years', 3600, fn () => TeamSeason::query()
            ->distinct()
            ->orderByDesc('season_year')
            ->pluck('season_year')
            ->all());
    }

    /**
     * Conferences with their members, in conference name order.
     */
    #[Computed]
    public function grouped()
    {
        $memberships = TeamSeason::query()
            ->with(['team:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark'])
            ->where('season_year', $this->year)
            // A division scope filters on classification; a conference id
            // narrows the page to that one group.
            ->when(ctype_digit($this->scope),
                fn ($q) => $q->where('conference_id', (int) $this->scope),
                fn ($q) => $q->where('classification', strtoupper($this->scope)))
            ->whereNotNull('conference_id')
            ->get();

        if ($this->q !== '') {
            $needle = str($this->q)->lower()->toString();

            $memberships = $memberships->filter(fn (TeamSeason $m) => str_contains(
                str($m->team?->display_name ?? '')->lower()->toString(),
                $needle
            ));
        }

        $conferences = Conference::whereIn('id', $memberships->pluck('conference_id')->unique())
            ->get(['id', 'name', 'short_name', 'abbreviation', 'logo'])
            ->keyBy('id');

        return $memberships
            ->groupBy('conference_id')
            ->sortBy(fn ($rows, $id) => $conferences[$id]->name ?? 'zz')
            ->map(fn ($rows, $id) => [
                'conference' => $conferences[$id] ?? null,
                'teams' => $rows->sortBy(fn (TeamSeason $m) => $m->team?->display_name)->values(),
            ]);
    }

    /** Records, so a team row is worth reading rather than just a name. */
    #[Computed]
    public function records()
    {
        return Cache::remember("teams:records:{$this->year}", 900, fn () => Standing::fromEspn()
            ->where('season_year', $this->year)
            ->get(['team_id', 'overall_wins', 'overall_losses', 'overall_ties'])
            ->mapWithKeys(fn (Standing $s) => [$s->team_id => $s->overallRecord()])
            ->all());
    }
}; ?>

<div class="flex flex-col gap-4">
    <h1 class="sr-only">Teams</h1>

    {{-- The one filter-row shape: search owns the row, WHO beside it as a
         text button, WHEN far right. --}}
    <x-filter-bar placeholder="Search teams…">
        <x-scope-filter :year="$year" :selected="$scope" :top25="false" :include-fcs="true" class="shrink-0" />

        <x-slot:actions>
            <x-season-menu :years="$this->years" :selected="$year" class="shrink-0" />
        </x-slot:actions>
    </x-filter-bar>

    @forelse ($this->grouped as $group)
        <div class="flex flex-col gap-2" wire:key="conf-{{ $group['conference']?->id }}">
            <flux:subheading>
                <x-conference-link :conference="$group['conference']" :year="$year" />
            </flux:subheading>

            <div class="grid gap-1.5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($group['teams'] as $membership)
                    <div class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 transition-colors hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700"
                         wire:key="team-{{ $membership->team_id }}">
                        <x-team-link :team="$membership->team" size="sm" class="min-w-0 flex-1" />

                        <span class="tabular shrink-0 text-micro text-zinc-400">
                            {{ $this->records[$membership->team_id] ?? '' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <flux:callout icon="users">
            <flux:callout.heading>No teams</flux:callout.heading>
            <flux:callout.text>Nothing matches for {{ $year }}.</flux:callout.text>
        </flux:callout>
    @endforelse
</div>
