{{--
    The page is its form — Filament schema components only, because the
    panel loads no app stylesheet and a Tailwind utility written here would
    have no definition behind it (the Branding page's own lesson).
--}}
<x-filament-panels::page>
    {{ $this->form }}
</x-filament-panels::page>
