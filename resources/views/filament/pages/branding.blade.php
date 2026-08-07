{{--
    The page is its form. Everything visible is a Filament schema component, so
    it renders with the panel's own stylesheet — which is the only stylesheet
    the panel loads. Tailwind utilities written here would have no definitions
    behind them and the page would come out as one unaligned column, exactly as
    the first Sync Health page did.
--}}
<x-filament-panels::page>
    {{ $this->form }}
</x-filament-panels::page>
