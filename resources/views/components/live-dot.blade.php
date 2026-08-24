{{--
    The live pulse — the one dot that means "in progress right now". It
    inherits its color (`bg-current`) so the label beside it decides the
    tint, and it is decorative by definition: the WORD next to it carries
    the meaning, so screen readers hear "Live", never a mystery bullet.
    Reduced-motion neutralization rides the global media block in app.css.
--}}
<span {{ $attributes->class(['size-1.5 animate-pulse rounded-full bg-current']) }} aria-hidden="true"></span>
