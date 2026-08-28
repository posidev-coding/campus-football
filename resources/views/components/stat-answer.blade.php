@props([
    /** none | idle | offer | capped | answered | missed — AsksQuestions::askState(). */
    'state' => 'none',
    /** array{kind:string,...}|null — a resolved answer, never a model's prose. */
    'answer' => null,
    /** list<string> — questions to offer on the idle screen. */
    'examples' => [],
])

{{--
    The stat answer, above the ordinary results — which still render, always.

    STRICTLY ADDITIVE. This only ever appears where search found nothing, so it
    cannot displace a result; the worst it can do is take up a card's height on
    a screen that was already a dead end.

    THE ANSWER IS A FACT and is printed plainly — search serves Scores and
    League, and a joke between a reader and a number makes the number look less
    trustworthy. Only the chrome speaks: the offer, the shrug and the cap.

    The ask is an EXPLICIT TAP rather than something the debounce fires. A live
    search re-renders on every pause in typing, so an automatic ask would
    classify "how many passing", then "how many passing yards", then "how many
    passing yards did" — three calls to reach the question, and a bill that
    scales with how slowly somebody types.

    Must be used inside a Livewire component defining `ask()`; both search
    surfaces do.
--}}
@if ($state === 'answered' && $answer)
    <div class="rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-800">
        <div class="flex items-center gap-2">
            <flux:icon.sparkles variant="mini" class="shrink-0 text-zinc-400" />
            {{-- The LABEL gives way first at 390px: it is the half the reader
                 already knows (they asked for it), while the context says
                 which season answered and is the half they cannot infer. --}}
            <span class="min-w-0 truncate text-sm font-semibold">{{ $answer['label'] }}</span>
            <flux:badge size="sm" color="zinc" class="ms-auto">{{ $answer['context'] }}</flux:badge>
        </div>

        @if ($answer['kind'] === 'value')
            {{-- The number leads and the name follows it: the reader asked for
                 a number, and the name is how they check we understood. --}}
            <a
                href="{{ $answer['href'] }}"
                wire:navigate
                class="mt-2 flex items-baseline gap-2 hover:underline"
            >
                <span class="text-2xl font-bold tabular-nums">{{ $answer['value'] }}</span>
                <span class="min-w-0 truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $answer['name'] }}</span>
            </a>
        @else
            <ol class="mt-2 flex flex-col gap-1">
                @foreach ($answer['rows'] as $row)
                    <li wire:key="ans-{{ $row['rank'] }}">
                        <a href="{{ $row['href'] }}" wire:navigate class="flex items-baseline gap-2 text-sm hover:underline">
                            <span class="w-4 shrink-0 text-right tabular-nums text-zinc-400">{{ $row['rank'] }}</span>
                            <span class="min-w-0 truncate">{{ $row['name'] }}</span>
                            @if ($row['team'])
                                <span class="shrink-0 text-xs text-zinc-400">{{ $row['team'] }}</span>
                            @endif
                            <span class="ms-auto shrink-0 font-semibold tabular-nums">{{ $row['value'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
@elseif ($state === 'capped')
    <flux:callout icon="clock">
        <flux:callout.text>{{ App\Support\Voice::line('search.ask.capped') }}</flux:callout.text>
    </flux:callout>
@elseif ($state === 'missed')
    <flux:callout icon="sparkles">
        <flux:callout.text>{{ App\Support\Voice::line('search.ask.none') }}</flux:callout.text>
    </flux:callout>
@elseif ($state === 'idle' && $examples)
    {{-- THE DISCOVERY SURFACE. Everything else here waits for somebody to
         already know they can ask; this is the screen that tells them, and it
         is the first thing a reader sees every time search opens.

         Real questions rather than a description of what questions look like:
         one tap answers it, with their own team's name in it, and the shape
         is learned by having used it once. Every line is built from a metric
         the resolver accepts — see AskExamples for why a suggestion that would
         be declined is worse than no suggestion at all. --}}
    <div class="flex flex-col gap-2 rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700">
        <p class="flex items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400">
            <flux:icon.sparkles variant="micro" class="shrink-0" />
            {{ App\Support\Voice::line('search.ask.idle') }}
        </p>

        <div class="flex flex-col gap-1">
            @foreach ($examples as $i => $example)
                {{-- The INDEX, never the text: a Livewire action is a public
                     endpoint, and this way it can only ask what we wrote. --}}
                <button
                    type="button"
                    wire:click="askExample({{ $i }})"
                    wire:loading.attr="disabled"
                    wire:target="askExample"
                    wire:key="ask-eg-{{ $i }}"
                    class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-start text-sm transition-colors hover:border-zinc-300 disabled:opacity-60 dark:border-zinc-800 dark:hover:border-zinc-700"
                >
                    <span class="min-w-0 flex-1">{{ $example }}</span>
                    <flux:icon.chevron-right variant="micro" class="shrink-0 text-zinc-400" />
                </button>
            @endforeach
        </div>

        <flux:icon.loading wire:loading wire:target="askExample" class="size-4 self-center text-zinc-400" />
    </div>
@elseif ($state === 'offer')
    <div class="flex flex-col gap-2 rounded-xl border border-dashed border-zinc-300 px-4 py-3 dark:border-zinc-700">
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ App\Support\Voice::line('search.ask') }}</p>

        {{-- The label stays plain. A joke standing between somebody and the
             button they are about to press is friction, not voice.

             The icon rides the `icon` PROP rather than a child: flux:button
             lays its slot out itself, and an icon passed as a child wrapped
             onto its own line above the label at 390px. The in-flight tell
             sits beside the button, the way the search input's does. --}}
        <div class="flex items-center gap-2">
            <flux:button wire:click="ask" wire:loading.attr="disabled" wire:target="ask" size="sm" icon="sparkles">
                Look it up
            </flux:button>

            <flux:icon.loading wire:loading wire:target="ask" class="size-4 shrink-0 text-zinc-400" />
        </div>
    </div>
@endif
