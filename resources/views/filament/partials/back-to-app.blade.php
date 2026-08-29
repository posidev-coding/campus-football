{{--
    The PWA-safe exit. The installed app strips every piece of browser chrome,
    so this chip is the only guaranteed way back out of /admin on a phone.

    A plain anchor, never wire:navigate — this crosses into the Flux front
    end's asset bundle. Lives in the @source'd tree so its Tailwind compiles;
    <x-filament::icon> renders through Filament's own pipeline, so the panel's
    DisableBladeIconComponents middleware does not affect it.
--}}
<a href="{{ route('home') }}"
   class="me-2 inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium
          text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">
    <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="h-4 w-4" />
    <span class="hidden sm:inline">Back to app</span>
</a>
