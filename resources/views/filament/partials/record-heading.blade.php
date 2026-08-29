{{--
    One parameterized heading for every record view page in the panel, so
    fifteen resources do not each grow a bespoke header that drifts.

    A view IS Htmlable, which is what `getHeading()` accepts — so a page hands
    this its own identity and gets the store-detail shape for free: image or
    initials, the name, status badges, and a row of icon'd meta facts.

    Lives under resources/views/filament, which is one of the two trees the
    panel's Tailwind scans. Anywhere else and every class here compiles to
    nothing, silently.
--}}
@props([
    'image' => null,
    'initials' => null,
    'title',
    'badges' => [],
    'meta' => [],
])
<div class="flex items-center gap-4">
    @if ($image)
        <img src="{{ $image }}" alt="" class="h-14 w-14 rounded-xl object-contain" />
    @elseif ($initials)
        <div class="flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-lg font-bold text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $initials }}</div>
    @endif

    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <span class="truncate text-2xl font-bold tracking-tight">{{ $title }}</span>

            @foreach ($badges as $badge)
                <x-filament::badge :color="$badge['color'] ?? 'gray'">{{ $badge['label'] }}</x-filament::badge>
            @endforeach
        </div>

        <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
            @foreach ($meta as $item)
                <span class="inline-flex items-center gap-1">
                    <x-filament::icon :icon="$item['icon']" class="h-4 w-4" />{{ $item['label'] }}
                </span>
            @endforeach
        </div>
    </div>
</div>
