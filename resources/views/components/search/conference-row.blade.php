@props(['conference'])

@php
    use App\Support\TeamGlance;

    $size = TeamGlance::conferenceSizes()[$conference->id] ?? null;

    $subtext = collect([
        $size ? $size['teams'].' '.str('team')->plural($size['teams']) : null,
        $size['classification'] ?? null,
    ])->filter()->implode(' · ');
@endphp

<x-search.row :href="route('conference', $conference)" :attributes="$attributes">
    @if ($conference->logo)
        <img src="{{ $conference->logo }}" alt="" loading="lazy" class="size-8 shrink-0 object-contain">
    @else
        <flux:icon name="trophy" variant="mini" class="size-8 shrink-0 p-1.5 text-zinc-400" />
    @endif

    <span class="min-w-0 flex-1">
        <span class="block truncate text-sm">{{ $conference->name }}</span>

        @if ($subtext !== '')
            <span class="block truncate text-micro text-zinc-500">{{ $subtext }}</span>
        @endif
    </span>
</x-search.row>
