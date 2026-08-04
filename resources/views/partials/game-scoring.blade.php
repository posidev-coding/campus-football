{{--
    The scoring summary, in the order the points were actually scored.

    Ordered by the stored `sequence` rather than by period and clock: a football
    clock counts DOWN, so sorting by clock ascending within a quarter reverses
    it and puts the closing drive first.
--}}
<div class="flex flex-col gap-2">
    @php $period = null; @endphp

    @forelse ($this->scoringPlays as $play)
        @if ($play->period !== $period)
            @php $period = $play->period; @endphp
            <flux:subheading class="mt-2 first:mt-0">
                {{ $period > 4 ? 'Overtime'.($period > 5 ? ' '.($period - 4) : '') : $period.($period == 1 ? 'st' : ($period == 2 ? 'nd' : ($period == 3 ? 'rd' : 'th'))).' Quarter' }}
            </flux:subheading>
        @endif

        <div class="flex items-start gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800"
             wire:key="play-{{ $play->id }}">
            <x-team-logo :team="$play->team" size="sm" class="mt-0.5 shrink-0" />

            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <div class="flex items-center gap-2">
                    <span class="shrink-0 rounded bg-zinc-100 px-1 py-px text-micro font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        {{ $play->abbreviation ?? 'SCORE' }}
                    </span>
                    <span class="tabular text-micro text-zinc-400">{{ $play->clock }}</span>
                </div>
                <p class="text-stat text-zinc-700 dark:text-zinc-300">{{ $play->text }}</p>
            </div>

            <div class="tabular shrink-0 text-right text-stat font-semibold">
                {{ $play->away_score }}–{{ $play->home_score }}
            </div>
        </div>
    @empty
        <flux:callout icon="flag">
            <flux:callout.heading>No scoring plays</flux:callout.heading>
            <flux:callout.text>Nothing published for this game yet.</flux:callout.text>
        </flux:callout>
    @endforelse
</div>
