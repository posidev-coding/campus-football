{{--
    The live tab: probability now, then the drive feed newest-first — the
    play feed a second-screen viewer actually follows. Everything reads our
    own database; the 30s poll and the two-minute sweep keep it moving.
--}}
<div
    class="chart-pair flex flex-col gap-4"
    style="--chart-away: {{ $this->chartColors[0] }}; --chart-home: {{ $this->chartColors[1] }}"
>
    @if ($this->winProbability !== [])
        <x-win-prob-chart :game="$game" :points="$this->winProbability" />
    @endif

    @if ($this->drives !== [])
        <div class="flex flex-col gap-2">
            <h3 class="text-micro font-semibold tracking-wide text-zinc-400 uppercase">Drives</h3>
            <x-drive-list :game="$game" :drives="$this->drives" newest-first />
        </div>
    @elseif ($this->winProbability === [])
        <flux:callout icon="clock">
            <flux:callout.heading>Underway</flux:callout.heading>
            <flux:callout.text>
                Drives and win probability land with the first box score sweep,
                usually within a couple of minutes of kickoff.
            </flux:callout.text>
        </flux:callout>
    @endif
</div>
