{{--
    A BROADCAST NETWORK'S MARK, where a caption used to spell its name.

    ESPN's scoreboard ships artwork for its own family (ESPN, ESPN+, ESPNU,
    SEC Network, ACC Network, ABC, CW) and none for FOX, CBS, NBC, FS1 or
    BTN — measured 2026-09-02 — so this renders the mark where we hold one
    and the NAME where we do not. The name is the fact `games.broadcasts`
    stores; a network with no mark is a network, not a hole, and nothing
    here invents a picture to stand in for it.

    Two images, the x-team-logo grammar: ESPN's dark variant where it sent
    one, and the light mark in both modes where it did not (its own
    `darkLogo` of "" — the red wordmark serves both surfaces). The marks are
    WIDE (the wordmark is 4:1), so `size` is a HEIGHT and the width follows;
    `alt` carries the name so a screen reader still hears "ESPN".
--}}
@props([
    /** The short name as `games.broadcasts` stores it — "ESPN", "SEC Network". */
    'network',
    /** `xs` rides a text-micro caption, `sm` a text-sm line, `md` a chip. */
    'size' => 'xs',
])

@php
    $mark = App\Support\Networks::mark($network);

    $height = match ($size) {
        'xs' => 'h-3.5',
        'sm' => 'h-4',
        'md' => 'h-5',
        default => $size,
    };
@endphp

@if ($mark === null)
    <span {{ $attributes }}>{{ $network }}</span>
@else
    <img
        src="{{ $mark['logo'] }}"
        alt="{{ $network }}"
        loading="lazy"
        decoding="async"
        {{ $attributes->class([$height, 'inline-block w-auto max-w-full shrink-0 object-contain align-middle', 'dark:hidden' => $mark['logo_dark'] !== null]) }}
    >

    @if ($mark['logo_dark'] !== null)
        <img
            src="{{ $mark['logo_dark'] }}"
            alt="{{ $network }}"
            loading="lazy"
            decoding="async"
            {{ $attributes->class([$height, 'hidden w-auto max-w-full shrink-0 object-contain align-middle dark:inline-block']) }}
        >
    @endif
@endif
