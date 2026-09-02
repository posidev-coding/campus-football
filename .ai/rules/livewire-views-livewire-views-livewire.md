---
paths:
  - 'config/livewire.php,resources/views/livewire/home.blade.php,resources/views/livewire/onboarding.blade.php,resources/views/livewire/tour.blade.php'
---

# Livewire Views Livewire Views Livewire

## Smart wire keys are OFF — a nested child's key must not depend on its siblings
Livewire 4's `smart_wire_keys` appends whatever `wire:key` and loop context rendered BEFORE a `<livewire:>` tag to that child's key, and never clears it when the keyed element closes — so a child's key depends on what its siblings rendered. Reproduced 2026-09-02: Home's onboarding child carried a leaked loop index on the no-team render (`lw-…-1-0`) and lost it on the `team-followed` refresh; a moved key is a NEW child, so the signup splash inside it came back with `show: false` half a second into its 12.5s and the tour claimed the screen. The tour remounted on every refresh for the same reason. The flag is false in config/livewire.php (compiled views must be cleared for it to take: `php artisan view:clear`); nothing here mounts a child inside a loop without its own `:key`, and it must stay that way. GuidedSetupTest pins that the wizard and the tour keep their wire:ids across the refresh. Do not turn the flag back on to "fix" a keyless child in a loop — give it a `:key`.
