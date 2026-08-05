@props(['first' => true, 'remaining' => 1, 'query' => '', 'matches' => [], 'error' => ''])

{{--
    The empty slot at the end of the team swiper: search, tap, and the card
    becomes a real team without ever leaving Home.

    Dashed rather than solid, so it reads as a placeholder among filled cards
    rather than a team whose logo failed to load — the same language the
    Pick'em teaser uses for "not a thing yet".
--}}
<div {{ $attributes->class(['flex flex-col rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700']) }}>
    <div class="flex flex-col gap-1 px-4 py-3">
        <span class="flex items-center gap-2">
            <flux:icon name="plus-circle" variant="mini" class="text-zinc-400" />
            <span class="font-semibold">{{ $first ? 'Add your team' : 'Add another' }}</span>
        </span>

        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ $first
                ? App\Support\Voice::line('home.first_team')
                : App\Support\Voice::line('home.another_team', ['remaining' => $remaining]) }}
        </p>
    </div>

    <div class="flex flex-col gap-2 px-4 pb-3">
        {{-- `.live` so results appear as they type; the debounce keeps it to
             one round trip per pause rather than one per keystroke. --}}
        <flux:input
            wire:model.live.debounce.250ms="teamQuery"
            icon="magnifying-glass"
            placeholder="Search FBS teams…"
            size="sm"
            clearable
        />

        @if ($error !== '')
            <p class="text-micro text-amber-600 dark:text-amber-500">{{ $error }}</p>
        @endif

        @if (trim($query) !== '')
            <div class="flex flex-col gap-1">
                @forelse ($matches as $match)
                    <x-team-pick-row
                        :team="$match"
                        wire:click="addTeam({{ $match['id'] }})"
                        wire:key="quickadd-{{ $match['id'] }}"
                    />
                @empty
                    <p class="px-1 text-micro text-zinc-500">
                        {{ App\Support\Voice::line('teams.no_matches', ['query' => trim($query)]) }}
                    </p>
                @endforelse
            </div>
        @endif
    </div>
</div>
