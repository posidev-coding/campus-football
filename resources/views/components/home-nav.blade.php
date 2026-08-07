{{--
    Home's own brand bar, and the shelf gamification lands on.

    Phone-only, matching the search bar directly below it: from `sm` up the
    layout header already carries the brand, the area nav and the account menu,
    and a second lockup on the same screen is a duplicate rather than an
    addition.

    It does NOT stick, and that is the whole design. The search bar underneath
    is `sticky top-0` so search stays one tap away however far Home has been
    scrolled; pinning this as well would put two bars of chrome permanently at
    the top of a 390px screen, in an app that deliberately cut its chrome from
    197px to 73px. So the brand greets you on arrival and gets out of the way,
    and the scrolled state is exactly what it was before this existed.

    `-mt-5` cancels the layout container's `py-5` — this is Home's first child
    now, which is the job the search bar used to do — and the breathing room
    comes back as `pt-3` INSIDE, so it belongs to the bar rather than being
    space the bar sits below.
--}}
<div {{ $attributes->class(['-mt-5 flex items-center justify-between gap-3 pt-3 sm:hidden']) }}>
    <a href="{{ route('home') }}" wire:navigate class="min-w-0">
        <x-brand.lockup size="md" />
    </a>

    {{-- Reserved for gamification: currency, XP, streak. Empty today and the
         row still reads as a nav, because the lockup is carrying it — which is
         the point of building the shelf before the things that stand on it. --}}
    <div class="flex shrink-0 items-center gap-2">
        {{ $slot }}
    </div>
</div>
