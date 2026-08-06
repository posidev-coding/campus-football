{{--
    The story of the game: the recap article leads, then who won it (game
    leaders), how it swung (win probability), then the reading list. The
    box score and play-by-play live on their own tabs — this one is for the
    reader who asked "what happened".
--}}
@php
    $recap = $this->articles->first(fn ($article) => $article->pivot->role === 'recap');
    $related = $this->articles->filter(fn ($article) => $article->pivot->role !== 'recap');
@endphp

<div
    class="chart-pair flex flex-col gap-4"
    style="--chart-away: {{ $this->chartColors[0] }}; --chart-home: {{ $this->chartColors[1] }}"
>
    @if ($recap)
        <x-article-card :article="$recap" />
    @endif

    @if ($this->gameLeaders !== [])
        <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
            <h3 class="text-micro font-semibold tracking-wide text-zinc-400 uppercase">Game leaders</h3>

            @foreach ($this->gameLeaders as $row)
                <div class="flex flex-col gap-1.5" wire:key="gldr-{{ $row['label'] }}">
                    <span class="text-center text-micro text-zinc-400">{{ $row['label'] }}</span>

                    <div class="grid grid-cols-2 gap-3">
                        @foreach (['away', 'home'] as $sideKey)
                            <div @class(['flex min-w-0 flex-col gap-0.5', 'items-end text-right' => $sideKey === 'home'])>
                                @if ($row[$sideKey] !== null)
                                    @if ($row[$sideKey]['athlete'])
                                        <x-player-link :athlete="$row[$sideKey]['athlete']" size="xs" />
                                    @else
                                        {{-- A box score names everyone; the roster only names this season. --}}
                                        <span class="truncate text-stat font-medium">{{ $row[$sideKey]['name'] }}</span>
                                    @endif
                                    <span class="tabular text-micro text-zinc-500">{{ $row[$sideKey]['display'] }}</span>
                                @else
                                    <span class="text-micro text-zinc-400">—</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($this->winProbability !== [])
        <x-win-prob-chart :game="$game" :points="$this->winProbability" />
    @endif

    @if ($related->isNotEmpty())
        <div class="flex flex-col gap-2">
            <h3 class="text-micro font-semibold tracking-wide text-zinc-400 uppercase">More on this game</h3>

            @foreach ($related as $article)
                <x-article-card :article="$article" compact wire:key="rel-{{ $article->id }}" />
            @endforeach
        </div>
    @endif

    @if ($recap === null && $this->gameLeaders === [] && $this->winProbability === [] && $related->isEmpty())
        <flux:callout icon="chart-bar">
            <flux:callout.heading>No recap yet</flux:callout.heading>
            <flux:callout.text>Leaders, the probability swing and the story land with the final box score.</flux:callout.text>
        </flux:callout>
    @endif
</div>
