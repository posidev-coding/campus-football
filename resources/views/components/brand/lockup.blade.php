@props([
    'stacked' => false,
    'size' => 'md',
])

@php
    $wordmark = App\Support\Brand::wordmark();

    /*
     * The vendor ships the lockup as an SVG whose <text> names Archivo by
     * family. An SVG loaded through <img> cannot see the page's fonts, so
     * those files render in system sans wherever they are used — which is why
     * this is HTML text around an inline mark instead. It also makes the
     * wordmark selectable, readable by a screen reader, and editable from the
     * App Branding page without redrawing anything.
     */
    $scale = match ($size) {
        'sm' => ['mark' => 'size-6', 'lead' => 'text-[0.5rem]', 'main' => 'text-base', 'gap' => 'gap-2'],
        'lg' => ['mark' => 'size-12', 'lead' => 'text-micro', 'main' => 'text-3xl', 'gap' => 'gap-3'],
        default => ['mark' => 'size-8', 'lead' => 'text-[0.5625rem]', 'main' => 'text-xl', 'gap' => 'gap-2.5'],
    };
@endphp

<div
    {{ $attributes->class([
        'flex',
        $scale['gap'],
        'flex-col items-center text-center' => $stacked,
        'items-center' => ! $stacked,
    ]) }}
>
    <x-brand.mark class="{{ $scale['mark'] }} shrink-0" />

    {{-- `leading-none` on both lines: the wordmark is two lines pretending to
         be one object, and default leading opens a gap that makes it read as a
         heading with a kicker above it. --}}
    <div @class(['flex min-w-0 flex-col', 'items-center' => $stacked, 'items-start' => ! $stacked])>
        {{-- The tracking puts a full 0.3em of air AFTER the last letter, which
             throws the centering out by half that in the stacked variant. The
             negative margin-end takes it back. --}}
        <span class="{{ $scale['lead'] }} -me-[0.3em] font-semibold whitespace-nowrap text-zinc-500 tracking-[0.3em] uppercase dark:text-zinc-400">
            {{ $wordmark['lead'] }}
        </span>

        <span class="{{ $scale['main'] }} font-extrabold whitespace-nowrap text-brand-ink -tracking-[0.025em] dark:text-brand-cream">
            {{ $wordmark['main'] }}
        </span>
    </div>
</div>
