<?php

use App\Models\Recruit;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Recruiting class rankings and team classes.
 *
 * Prospects are ingested top-down by national rank, so this shows the top of a
 * class rather than an arbitrary slice — see SyncRecruiting for why the full
 * ~5,200-prospect class is not ingested by default.
 */
new class extends Component
{
    #[Url]
    public ?int $class = null;

    #[Url]
    public string $team = '';

    #[Url]
    public string $view = 'players';

    public function mount(?int $class = null): void
    {
        $this->class = $class ?? $this->latestClass();
    }

    private function latestClass(): int
    {
        return (int) (Recruit::max('recruiting_class') ?? config('cfb.season'));
    }

    /** @return list<int> */
    #[Computed]
    public function classes(): array
    {
        return Cache::remember(
            'recruiting:classes',
            3600,
            fn () => Recruit::query()->distinct()->orderByDesc('recruiting_class')->pluck('recruiting_class')->all()
        );
    }

    /** @return list<array{id:int, name:string}> */
    #[Computed]
    public function teams(): array
    {
        return Cache::remember(
            "recruiting:teams:{$this->class}",
            3600,
            fn () => Team::query()
                ->whereIn('id', Recruit::where('recruiting_class', $this->class)
                    ->whereNotNull('committed_team_id')
                    ->distinct()
                    ->pluck('committed_team_id'))
                ->orderBy('display_name')
                ->get(['id', 'display_name'])
                ->map(fn (Team $t) => ['id' => $t->id, 'name' => $t->display_name])
                ->all()
        );
    }

    #[Computed]
    public function prospects()
    {
        return Recruit::query()
            // `slug` is the Team route key — omitting it from a constrained
            // eager load makes route() fail with "missing required parameter",
            // which looks like a null relation but is a missing column.
            ->with(['committedTeam:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark', 'position:id,abbreviation'])
            ->where('recruiting_class', $this->class)
            ->when($this->team !== '', fn ($q) => $q->where('committed_team_id', (int) $this->team))
            ->ranked()
            ->limit(200)
            ->get();
    }

    /**
     * Team classes ranked by the average grade of their signees — ESPN
     * publishes per-prospect grades but no class ranking, so this is derived.
     */
    #[Computed]
    public function teamClasses()
    {
        return Recruit::query()
            ->with('committedTeam:id,slug,display_name,short_display_name,abbreviation,logo,logo_dark')
            ->where('recruiting_class', $this->class)
            ->whereNotNull('committed_team_id')
            ->get()
            ->groupBy('committed_team_id')
            ->map(fn ($signees) => [
                'team' => $signees->first()->committedTeam,
                'count' => $signees->count(),
                'average' => round($signees->avg('grade') ?? 0, 1),
                'best' => $signees->min('national_rank'),
            ])
            // A prospect can be committed to a school outside the divisions we
            // carry, which leaves the relation null and would blow up route().
            ->filter(fn (array $row) => $row['team'] !== null)
            ->sortByDesc(fn (array $row) => [$row['average'], $row['count']])
            ->values();
    }
}; ?>

<div class="flex flex-col gap-4">
    <flux:heading size="xl">Recruiting</flux:heading>

    <div class="flex flex-wrap gap-2">
        <flux:select wire:model.live="class" size="sm" class="w-28">
            @foreach ($this->classes as $year)
                <flux:select.option :value="$year">{{ $year }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:radio.group wire:model.live="view" variant="segmented" size="sm">
            <flux:radio value="players" label="Players" />
            <flux:radio value="teams" label="Team classes" />
        </flux:radio.group>

        @if ($view === 'players')
            <flux:select wire:model.live="team" size="sm" class="min-w-40 flex-1">
                <flux:select.option value="">All schools</flux:select.option>
                @foreach ($this->teams as $t)
                    <flux:select.option :value="$t['id']">{{ $t['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($view === 'players')
        <div class="grid gap-2 xl:grid-cols-2">
        @forelse ($this->prospects as $recruit)
            {{-- `min-w-0` for the same reason the game card needs it: this is a
                 grid item, whose automatic minimum size is its MIN-CONTENT
                 width. The inner column truncates, but truncation cannot help
                 while the row is free to grow to fit the longest high school
                 and hometown — it reached 516px inside a 343px track and
                 scrolled the page sideways. --}}
            <div class="flex min-w-0 items-center gap-3 rounded-lg border border-zinc-200 p-2.5 dark:border-zinc-800"
                 wire:key="r-{{ $recruit->id }}">
                <span class="tabular w-8 shrink-0 text-right text-stat font-semibold text-zinc-400">
                    {{ $recruit->national_rank }}
                </span>

                <div class="flex min-w-0 flex-1 flex-col">
                    <span class="truncate text-sm font-medium">{{ $recruit->display_name }}</span>
                    <span class="truncate text-micro text-zinc-500">
                        {{ collect([$recruit->position?->abbreviation, $recruit->high_school, $recruit->hometown()])->filter()->implode(' · ') }}
                    </span>
                </div>

                @if ($recruit->committedTeam)
                    <x-team-link
                        :team="$recruit->committedTeam"
                        label="abbr"
                        size="xs"
                        class="shrink-0 text-zinc-500"
                    />
                @else
                    <flux:badge size="sm" color="zinc">{{ $recruit->status ?? 'Uncommitted' }}</flux:badge>
                @endif

                @if ($recruit->grade)
                    <span class="tabular w-8 shrink-0 text-right text-sm font-semibold">{{ $recruit->grade }}</span>
                @endif
            </div>
        @empty
            <flux:callout icon="academic-cap" class="xl:col-span-2">
                <flux:callout.heading>No prospects</flux:callout.heading>
                <flux:callout.text>Nothing ingested for the {{ $class }} class yet.</flux:callout.text>
            </flux:callout>
        @endforelse
        </div>
    @else
        <div class="stat-grid rounded-lg border border-zinc-200 dark:border-zinc-800">
            <table class="w-full text-stat">
                <thead>
                    <tr class="border-b border-zinc-200 text-micro uppercase tracking-wide text-zinc-500 dark:border-zinc-800">
                        <th class="px-3 py-2 text-left font-medium">School</th>
                        <th class="px-2 py-2 text-right font-medium">Signees</th>
                        <th class="px-2 py-2 text-right font-medium">Avg grade</th>
                        <th class="px-3 py-2 text-right font-medium">Top recruit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->teamClasses as $row)
                        <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800/60">
                            <td class="px-3 py-2">
                                <x-team-link :team="$row['team']" />
                            </td>
                            <td class="px-2 py-2 text-right">{{ $row['count'] }}</td>
                            <td class="px-2 py-2 text-right font-semibold">{{ $row['average'] }}</td>
                            <td class="px-3 py-2 text-right text-zinc-500">#{{ $row['best'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-4 text-center text-zinc-500">No commitments recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
