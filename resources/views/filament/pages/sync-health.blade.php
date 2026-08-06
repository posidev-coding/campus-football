{{--
    Deliberately empty: every section of this page is a Filament widget, so
    it renders with the panel's own stylesheet.

    The panel does NOT load resources/css/app.css, so Tailwind utilities
    written here have no definitions behind them — the first version of this
    page laid everything out with `grid grid-cols-2 gap-4` and rendered as one
    unaligned column, because none of those classes existed. Anything genuinely
    custom needs a Filament theme registered first.
--}}
<x-filament-panels::page />
