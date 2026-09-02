---
paths:
  - 'resources/views/components/group-hero.blade.php,resources/views/components/group-icon.blade.php,resources/views/livewire/group.blade.php'
---

# Components Views Livewire

## The clubhouse mark, and where the kind chip went below sm
AMENDS "x-group-hero's chip renders for both kinds" (components.md), which still holds — the chip just is not on the TITLE ROW below sm any more.

x-group-hero grew an `icon` slot defaulting to `<x-group-icon>`: the group's uploaded icon (`groups.icon`, a PATH on config('cfb.upload_disk')) or its initials. Null is the normal state and stays a first-class render — nothing writes a stand-in path or a default image, and `Group::iconUrl()` returning null is what every surface reads.

That mark cost the h1 five characters at 390px on a title row that was ALREADY truncating ("The Tes…" became "T…"). So: the mark is size-9 at base and size-11 from sm, and the Public/Private chip is `hidden sm:inline-block` with the kind moved to the head of the meta line below sm. The kind is still said on both sides at every width — GroupIconTest's "says the kind on both sides of the pair" counts it twice per render and reds if either half goes. Do not "restore" the chip to always-visible without giving the title row the width back. (Amended 2026-09-01: the Talk icon left the row for a gutter tab of its own, giving ~44px back; the band went light — white, zinc-200 border — so an uploaded mark has contrast, and the initials tile is zinc-100/zinc-700 in light.)

Writes go through App\Actions\SetGroupIcon (handle/clear). The commissioner seat is the whole gate and is enough alone: a public room is house-run with no commissioner seat, so a lobby can never reach the write. Delete the previous file AFTER the new path is committed, never before.
