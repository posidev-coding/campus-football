---
paths:
  - 'resources/views/livewire/lobby.blade.php,resources/views/livewire/pickem-home.blade.php,resources/views/partials/pickem-promise.blade.php'
---

# Partials

## Open means open at BOTH pick'em doors, guests included — and a guest reads the config mirror, not Pennant
Launch day 2026-09-02: guests read "Coming soon" on /picks and /lobby with the flag open, because both screens gated their real content on `auth()->check() && Feature::active('pickem')` and fell back to the promise partial verbatim. The flag's own definition already said OPEN MEANS OPEN, GUESTS INCLUDED; the doors did not. Now: the Lobby shows a guest its rooms (`showLobby()` reads `config('cfb.pickem_open')` for a guest, Pennant for a session) and a guest Join walks to register with the Lobby as `url.intended`; the Picks tab's fallback partial has two states — the promise verbatim while CLOSED, and with the flag OPEN no badge, the app invite's words and a Create-your-account door (`start()` on the host, intended = /picks). A guest must read the CONFIG mirror, never Pennant: a guest is the null scope and a persisted null-scope row keeps "closed" until `pennant:purge pickem`. The invite foot on the Lobby is `@auth` — it carries the reader's own handle. PickemHomeTest and LobbyTest pin the open-guest state; the closed-state tests stay.
