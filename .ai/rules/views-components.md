---
paths:
  - 'app/Services/Espn/Sync/SyncGames.php,app/Support/Networks.php,resources/views/components/network-logo.blade.php,resources/views/components/group-icon.blade.php'
---

# Views Components

## Network and conference marks: only what ESPN sent, on one read, never a stand-in
ESPN's scoreboard `geoBroadcasts[].media` carries `logo`/`darkLogo` for the Disney family only (ESPN, ESPN+, ESPNU, SEC Network, ACC Network, ABC, CW — measured 2026-09-02); FOX, CBS, NBC, FS1, BTN and Peacock are a bare `shortName` in every ESPN feed, summary and core `media` catalog included. SyncGames writes `networks` off the same request (nothing against the tiers) and a bare mention NEVER nulls a mark already held — fill the logo columns only when the payload carries one, and `darkLogo` "" means the light mark serves both surfaces (store null). `x-network-logo` prints the NAME where there is no mark; adding FOX/CBS means vendoring brand assets, a founder decision, not a lookup fallback. Both maps (App\Support\Networks, App\Support\ConferenceMarks) are plain cached arrays with a static memo flushed in tests/Pest.php — one read per screen, never per card; `Networks::forget()` after a sync write. A conference shield rides a WHITE puck in both modes because ESPN ships no dark `conferences.logo`; a conference with no synced logo keeps the mode tile.
