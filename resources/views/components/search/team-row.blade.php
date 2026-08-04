@props(['team'])

@php
    use App\Support\TeamGlance;
    use Illuminate\Support\Number;

    $rank = TeamGlance::ranks()[$team->id] ?? null;
    $record = TeamGlance::records()[$team->id] ?? null;
    $conference = TeamGlance::conferenceNames()[$team->id] ?? null;
    $position = TeamGlance::standingPositions()[$team->id] ?? null;

    /*
     * "SEC · 11-2 (7-1) · 3rd in SEC". Every segment is optional — an FCS
     * team has no standing row and an offseason team has no record — and the
     * row has to read right with any of them missing.
     */
    $subtext = collect([
        $conference,
        $record ? trim($record['overall'].' ('.$record['conference'].')') : null,
        $position !== null && $conference ? Number::ordinal($position).' in '.$conference : null,
    ])->filter()->implode(' · ');
@endphp

<x-search.row :href="route('team', $team)" :attributes="$attributes">
    <x-team-logo :team="$team" size="sm" />

    <span class="min-w-0 flex-1">
        <span class="flex min-w-0 items-baseline gap-1.5">
            {{-- Rank rides BESIDE the name, small and muted, the way the game
                 card and x-team-link already write it — part of the team's
                 identity, not another statistic competing with the record. --}}
            @if ($rank)
                <span class="tabular shrink-0 text-micro font-semibold text-zinc-400">{{ $rank }}</span>
            @endif
            <span class="truncate text-sm">{{ $team->display_name }}</span>
        </span>

        @if ($subtext !== '')
            <span class="block truncate text-micro text-zinc-500">{{ $subtext }}</span>
        @endif
    </span>
</x-search.row>
