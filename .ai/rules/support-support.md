---
paths:
  - 'app/Actions/GrantWalletEntry.php,app/Actions/EnterFilmRoom.php,app/Support/RankLadder.php,app/Support/PickemPreflight.php'
---

# Support Support

## Capped earns: the KEY is the cap, and the flip needs a Pennant purge
GrantWalletEntry::daily() caps a repeatable earn by the SHAPE OF ITS KEYS, never with throttle code: the football day (Eastern — 01:00 UTC Sunday is still Saturday night) is stamped into the key, so the (user_id, key) unique index IS the anti-farming cap. Pass a $slot when the thing being paid for is identifiable (a game id for the Film Room, so re-reading one box score earns once ever and burns one of the day's five); omit it for a post, where only the count matters. A race under-pays by one rather than paying twice — the safe direction. handle() returns bool now: false means the key was already spent, which is a no-op and not a failure. daily() refuses unverified accounts; the FIRST_TEAM onboarding seed is the one earn that does not come through it.

The Film Room fires from the game screen's mount() and updatedTab() and must NEVER move into render() — a live game re-renders every 30s, and the key would stop it paying twice but nothing would stop it asking twice.

RankLadder is a pure computation over walletTotals()['xp'] — no table, no stored column, rebalancing is a deploy. At the top rung `next`/`at`/`remaining` are NULL, never 0: callers SKIP the climb line rather than render a full bar under a promotion that is not coming. Rung names deliberately stay out of Voice — a rank is a label you compare with somebody else's, so it says the same word in every register.

THE FLIP: `pickem` reads config('cfb.pickem_open') / PICKEM_OPEN, so launch is an env change with instant rollback. Pennant's database driver PERSISTS resolved values, so flipping reaches NOBODY who already loaded a page until `php artisan pennant:purge pickem` clears their rows. pickem:preflight reports this (the `stored` row) plus what must be true underneath the flag; it never writes, never stocks, never flips.
