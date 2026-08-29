---
paths:
  - 'app/Support/SlateFeasibility.php,resources/views/livewire/group.blade.php,resources/views/livewire/slate-builder.blade.php,resources/views/livewire/pickem-home.blade.php'
---

# Livewire Views Livewire

## A group's build door is gated on the Saturday, and a group never downsizes
`SlateFeasibility::for($contest, $week, $saturday)` is the one answer to "can this group build this week": viable games (SuggestSlate's own candidate pass) vs the engine's slateSize(). A house room is PROVISIONED — LobbyCatalog::resolve() asks the same question before spawning and downsizes Shotgun to what exists — but a GROUP never downsizes: its mode is a season-long promise its members chose, so a thin Saturday takes the door away instead. Three surfaces read it and must stay in step: the clubhouse (button gone, both numbers stated), the My Picks card (blue CTA and its deadline replaced by "Not enough games this Saturday"), and the wizard itself, which refuses BEFORE firstOrCreate so a look around leaves no unpublishable draft. NULL/unknown means leave the door alone — never read "cannot tell" as "no". Cost: it is a suggestion pass, so a screen with many cards resolves the count ONCE (`fromCount()`), never per row.
