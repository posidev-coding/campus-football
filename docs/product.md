# Product: voice, identity, brand and follows

The register the app speaks in, who the user is, what the app looks like,
and how following a team works.

## The voice: `ContentRating` drives copy, and it is not decoration

This app is meant to be **fun, funny, and a bit of a wind-up**. That is a
product requirement, not a coat of paint applied at the end. A pick'em app that
reads like a spreadsheet has already lost to the group chat it is competing
with.

So `$user->content_rating` is not just a flag for generated taunts — it is the
register the whole interface speaks in. **Wherever there is copy with a
personality budget, write all three versions when you write the screen**, not
later: descriptions, subtext, empty states, button labels, confirmations,
tooltips, error messages, instructional text, notifications.

    Mild    Light Ribbing  clean, still warm — never limp
    Medium  Locker Room    the default; how the group chat actually talks
    Spicy   No Mercy       merciless about the picks, for the people who asked for it

The enum's cases are still backed by `pg` / `pg13` / `r` — those are the Voice
map's keys and the stored column, and only the display names changed.

### Where it applies, and where it must not

    LOUD   Account · Pick'em · Gamification · Groups · Notifications
           Anything about YOU, your picks, your record, your rivals.

    PURE   Scores · League (standings, rankings, stats, leaders, teams,
           players, recruiting, news)
           Someone checking a score wants the score. A joke between a reader
           and a fact is friction, and it makes the data look less trustworthy
           — which is the one thing this app cannot afford, given three
           rebuilds went wrong on data.

The line is not "serious vs silly", it is **whose content it is**. A scoreboard
reports what happened. A pick'em screen is talking TO somebody about what they
did, and that is where the voice belongs. Chrome that frames factual screens —
an empty state, an onboarding hint — can still carry personality; the facts
themselves stay untouched.

### Rules the voice does not get to break

- **Roast the pick, the team, the record — never the person.** Already stated
  on the enum, and it is what keeps this funny instead of a liability. It is
  also what keeps the mobile build inside its App Store age rating.
- **PG is not "the boring one".** If the PG variant reads like documentation
  while PG-13 is the only one with jokes, PG has been written as a punishment.
  Every level should feel like it was written on purpose.
- **Never let the joke eat the instruction.** If a user cannot tell what a
  control does after reading the funny version, the funny version is wrong.
- **Fall DOWN the ladder, never up.** `ContentRating::includes()` already
  encodes this: an R user can be shown PG copy, a PG user must never see PG-13.
  Missing copy at a level resolves downward.

### The resolver

`App\Support\Voice::line($key, $replace, $for)`. Copy lives in one map so all
three variants of a line sit side by side — which is how you catch PG being
written as a punishment. Resolution walks `includes()` in reverse and takes the
first level that exists, so a line defining only `pg` is safe to add and a line
defining only `r` never reaches anyone who did not ask for it. Unknown key
returns `''`, never the key.

Account is done and is the reference implementation. Note what was deliberately
left alone there:

- **the search placeholder** — an affordance, read every time the field is
  empty; the AT-LIMIT message beside it does speak, because that one is about
  something the user just did
- **the handle format rule** — "lowercase letters, numbers and underscores" is
  where a joke would eat the instruction
- **field labels and section headings** — people navigate by them

**Copy does not belong in exceptions.** `FollowLimitReached` carries a
developer message for logs; what the user reads comes from `Voice`, because a
string baked into an exception can only ever speak in one register.

## Identity: first/last name, a claimed handle, and a content rating

Registration collects **first and last name separately** and a **content
rating** — and deliberately NOT a handle. Nothing consumes a handle until
Pick'em and chat exist, so asking up front was a signup toll for a feature
that does not; the column is nullable and **null means never claimed** —
never a generated stand-in (the mistake that broke three previous versions
wears many hats). All of it is editable from Account. There is no `name`
column — `$user->name` is an accessor over the two halves, which is why
nothing that printed a user had to change.

**Handle, not username — CLAIMED, not collected.** It is the sport's own
vernacular, and it sets the expectation that this is the name you are shouted
at by rather than a login credential. Account shows a "claim your handle"
affordance (`profile.claim_handle`) in place of the `@handle` row until one
exists; the same edit-profile modal is the future seam for claiming at the
first Pick'em entry or chat message. Once claimed it can change but never
blank back to null (`nullable`-until-claimed, `required` after). Unique, and
case-insensitively so: the column's `utf8mb4_unicode_ci` collation makes the
unique index reject `@Taylor` when `@taylor` exists, which is the confusion a
handle is for preventing — multiple NULLs coexist under that index fine. On
edit the rule needs `Rule::unique(...)->ignore($user->id)` or saving any
other field fails against your own row.

**Mask the handle on the CLIENT, validate on the server.** Livewire will not
overwrite a focused input — that is what stops it clobbering your typing — so a
server-side clean leaves the visible text disagreeing with the stored value
until blur. `x-mask:dynamic` corrects the character as it is typed; the rule
stays as the guarantee.

**`ContentRating` replaced `TrashTalkIntensity`** — the same axis, and it has
now worn two vocabularies. It borrowed film ratings from the App Store's own
shorthand, and **that frame was reversed on 2026-08-31 for a heat scale** after
the registers were measured against each other: across all 239 Voice families,
PG and PG-13 contain no profanity at all and R contains exactly one mild word,
with the tiers growing longer and more merciless rather than more explicit. The
registers differ in ATTITUDE, not vocabulary — so a film rating described a
scale the roast-the-pick law forbids the app from ever delivering. It
over-promised to everyone who chose R, warned off the readers who would most
enjoy the best-written register, and volunteered an "R / Anything Goes" mode to
App Store review over one "damn": the shorthand borrowed to satisfy the age
rating was running the wrong way. Mild / Medium / Spicy needs no explaining
either and claims nothing about maturity — and the top rung is deliberately
"Spicy" rather than a "Nuclear" or a "Ghost Pepper", because an over-promising
top tier is the very fault being repaired and would land the same way in a new
vocabulary. Only the display names moved — the
backing values stayed `pg`/`pg13`/`r` (the Voice map's keys and the stored
column), so there was no migration and no line was rewritten. Default is
Medium, pre-selected at registration rather than blank — an unset radio group
reads as a decision you must research before you are allowed to sign up.

Two Flux details this turned up:

- **`flux:radio` in the `cards` variant nests its description inside an
  `if ($label)` branch.** Passing only a slot silently drops the description;
  pass `label` AND the slot, and the slot still wins for display.
- **Factories must satisfy the app's own rules.** `fake()->userName()` emits
  dots and capitals, so fixtures built a user the handle validation rejects —
  failing only on the runs where faker picked a name with a dot in it.

## The brand: one resolver, shipped defaults, editable overrides

The app's own identity is 1b Pennant — Ink `#0b0b0c`, Cream `#f5f2ea`, Lager
`#e8a33c`, Archivo. It used to be a Flux `trophy` glyph beside
`config('app.name')`, which was also the League tab's icon and the conference
icon: the brand mark and a navigation glyph were the same picture.

**Everything reads `App\Support\Brand`.** The files in `public/brand/` and the
constants on that class are the shipped default and are in git; the one
`brand_settings` row holds OVERRIDES, where a null column means "use the
shipped value". So a partial change is safe, Reset is nulling columns rather
than restoring a fixture, and an override whose uploaded file has gone missing
degrades to the shipped brand rather than to a broken image. `/admin/branding`
is the editor — labelled "App Branding" because `TeamResource` already owns
"Team Branding", which is a different thing entirely.

Six things this cost, each of which fails quietly:

- **There must be exactly ONE `<meta name="theme-color">`.** The appearance
  sync does `querySelector('meta[name=theme-color]')` and writes to whatever
  comes back first. The brand's own head snippet ships a media-scoped PAIR, and
  pasting it in hands the sync the dark tag and silently undoes the fix that
  stopped a phone's address bar staying black in Light mode. `BrandingTest`
  counts them.
- **A tracked zero-byte `public/favicon.ico` shadowed its own route.** The web
  server's `try_files` serves a real file before the request ever reaches PHP,
  so the empty one won and the route was unreachable. It had to be DELETED, not
  overwritten. Same for `site.webmanifest`: both are generated now, because
  their contents are editable and a second copy of the icon list is how a
  home-screen icon ends up disagreeing with the tab icon.
- **There is no ICO encoder on this machine or in PHP, and none is needed.**
  A 6-byte header, a 16-byte directory entry per image, then each PNG verbatim —
  PNG-in-ICO, supported everywhere since Vista. `Brand::ico()` packs the 16 and
  32px favicons. Verify with `file`, which reports the image count and sizes.
- **`@theme`, never `@theme static`.** Tailwind emits a theme color as a custom
  property and compiles `text-brand-ink` to `color: var(--color-brand-ink)`,
  which is the whole mechanism behind runtime retinting — the head emits a
  `:root` block ONLY for colors that differ, so a stock install carries no style
  block at all. `static` inlines the literal and makes the override a no-op.
- **The lockup is HTML text around an inline mark, never the vendor SVG.** Those
  files name Archivo by family, and an SVG loaded through `<img>` cannot see the
  page's fonts — the wordmark renders in system sans wherever it is used.
  Verified the other way: the computed `font-family` of the rendered lead line
  is `"Archivo Variable"`. An UPLOADED mark is the exception and is drawn as an
  `<img>` pair rather than inlined, because echoing uploaded SVG unescaped is a
  stored-XSS shape — which is why a custom mark needs both a light and a dark
  variant, having given up `currentColor`.
- **Filament gets the same brand through closures**, so an edit made in the
  panel is live in the panel. `brandLogo()` takes an `Htmlable` and renders it
  INLINE; a string renders as an `<img>` and hits the font problem above.
  `LocalFontProvider` wants a STYLESHEET url, not a font file — hence
  `public/brand/archivo.css` beside a stable copy of the woff2, since the front
  end's `@fonts` build emits a hashed filename that moves every build.

Uploads land on the `public` disk, which is ephemeral on Laravel Cloud. That is
the intended workflow rather than a limitation: iterate in the panel, then commit
the winner into `public/brand/` as the new shipped default.

## Follows are an ORDERED list; there is no favorite

A user follows up to `User::MAX_FOLLOWED_TEAMS` (5) teams and controls their
order. That order drives everything — the Home swipe order, the scoreboard
float order, whose news leads. **Position 1 is what "favorite" used to mean.**

`users.favorite_team_id` is gone, and the reason is worth keeping: singling
out one team forced every surface to RECONCILE it with the follow list. The
scoreboard had to union the favorite in, because a row written before
`SetFavoriteTeam` existed might not be followed; `UnfollowTeam` had to null
the column or leave a ghost team leading the home page. An ordered list makes
all of that unrepresentable.

    FollowTeam            appends at max(position) + 1 — a new follow never
                          outranks the teams already there
    UnfollowTeam          deletes, then REINDEXES to 1..N. Sparse positions
                          still sort correctly, which is what makes leaving
                          gaps easy; the cost lands on every later writer
    ReorderFollowedTeams  handle() validates the submitted list is EXACTLY
                          the user's followed set — it is reachable from a
                          public Livewire method

**`game_odds.favorite_team_id` is a different column.** It is the BETTING
favorite, written by `SyncOdds`. Anyone grepping "favorite" will hit it;
`OddsAndPredictorsTest` passing unchanged is the proof the right one went.

**`wire:sort` reports ONE item and its new index, not the whole list**, and
that index is 0-based (Sortable's `newIndex` — verified in
`vendor/livewire/livewire/dist/livewire.esm.js`, the sort of thing that
produces an off-by-one rather than an error). `ReorderFollowedTeams::place()`
rebuilds the full order from it so the drag path gets the same validation as
the keyboard path. Drag is not keyboard-reachable, so the up/down buttons are
not optional.

## Onboarding is one blue button, three small screens, then the moment

Home's getting-started card is the front door, and it makes two different
promises: guests see `Get started` under copy selling the whole app
(`onboarding.guest.*`) and step through **name → trash talk →
email+password** — "easy as 1-2-3" is the product promise, and a slim
three-segment progress bar (`x-signup-progress`, count kept as sr-only text)
says it wordlessly; signed-in users see `Add your team` under
favorite-forward copy (`onboarding.member.*`) and go straight to the
favorite-team moment. Both land in the same full-screen overlay
(`livewire/onboarding.blade.php`, `fixed inset-0 z-50` over app chrome at
z-40) rather than navigating — the same reason the search panel expands in
place.

- **The team picker is a MOMENT, not a step.** It sits past registration,
  wears no counter (including on the registration hand-off, which used to
  advertise itself as "Step 5 of 5"), and is styled as an arrival — centered
  mark, one question, one promise ("your favorite headlines your home page —
  you can add more later"). It collects EXACTLY ONE team: the first pick
  auto-completes the moment (`afterTeamAdded()` grants, stamps, and
  dispatches `team-followed` + `signup-splash` + `close-onboarding`). The
  "stack up to five" state is gone; the TOUR teaches the five slots now
  (`tour.glance.body`). There is deliberately no Back from it, and `next()`
  refuses to cross the credentials boundary; `register()` is that door.
- **The first pick pays 25 XP** (`GrantWalletEntry::FIRST_TEAM_XP`, key
  `first-team`) — the sole earn allowed before verification, a number in the
  wallet worth protecting. Once-ever by idempotency key; skipping pays
  nothing; Home's quick-add slot pays nothing.
- **Every primary button sits under its fields, not in a bottom rail.** The
  old rail pinned the button to the viewport bottom — a reach from the
  inputs, behind the keyboard on phones, with a gulf of whitespace above.
  Skip stays a whisper: at zero teams the picker is the action, and there is
  no Done at all anymore.
- **Skipping still costs nothing.** Home seats **Bandwagon State**
  (`App\Support\PlaceholderTeam`) in the swiper at zero follows, so the
  `glance` anchor exists and the tour runs either way. `done()` remains the
  skip path and dispatches `onboarding-finished` alongside `start-tour`.
- **Both exits play the signup splash — slower, darker.** ~13s now: 2400ms a
  phrase, ordered as a road trip — travel, field, song, THEN the high-five —
  with the Tallboys closing and holding ~2900ms before the 500ms fade to
  a plainly visible Home. The pace was slowed TWICE on real-phone review
  (850 → 1500 → 2400ms): this screen is the app introducing its whole
  personality, and it is allowed the seconds that takes — if it ever feels
  long, cut phrases, don't speed up. Forced dark
  (`class="dark" … bg-zinc-950`) whatever the theme: it is a curtain moment.
  Phrases personalize to the favorite (or to Bandwagon State, which is the
  joke). The tour waits on anything wearing `data-tour-holdoff` (wizard +
  splash), checked with `getClientRects()` — never `offsetParent`, which is
  null for `fixed` elements even while they fill the screen.
- **The registration hand-off renders the wizard pre-painted.** On the
  hand-off load (`opensToMoment()`) the overlay's `x-cloak` is omitted
  server-side; waiting for Alpine to boot flashed the home screen between
  registration and the moment.
- **Credentials come LAST**, which is a conversion choice and a security one:
  an abandoned signup has no password or email to leave anywhere. No handle
  anywhere in the flow — see the identity section.
- **The device draft (`localStorage['cfb.signup']`) stores only the first
  two screens' fields** (`first_name`, `last_name`, `content_rating`). Two
  independent protections, because either alone can be undone by a later
  edit: the explicit allowlist, AND no save handler on the credentials
  screen at all. Verify by READING storage, not by reading the code.
- **The draft saves from the ELEMENT that fired, never from `$wire`.** These
  bindings are deferred, so `$wire.first_name` is still empty while the user
  types into it — saving from component state wrote a step behind.
- **Every step needs its own `wire:key`** — `step-team` included. Without one
  Livewire morphs step one's input into step two's — same tag, same position —
  and the reused node kept its old binding long enough for a keystroke to land
  on the previous field.
- **`register()` does a FULL redirect** to a CLEAN `home` URL, not
  `navigate: true`: registering flips the whole page's auth state and every
  `@auth` region has to re-render. The redirect also means nothing client-side
  runs afterwards, which is why an authenticated load clears the draft. The
  classic `/register` screen makes the SAME hand-off, so header-form
  registrants reach the moment (and therefore the tour) too.
- **The hand-off is a one-load session flash (`onboarding.moment`), never a
  URL.** It used to be `?start=team`, and that query was a landmine: a
  home-screen install captures the tab's URL, so the param rode into the web
  clip and "Who's your team?" reopened on every launch of the installed app
  — and on every pull-to-refresh. The flash is consumed by the landing load,
  cannot be bookmarked, and keeps the address bar showing the same clean `/`
  the manifest's `start_url` promises. The old param is dead code, not
  gated: clips that already captured it go quiet on their own.
- **Dismissal reuses `onboarded_at`** (guests: a session flag) **and stamps
  `tour_completed_at`** — declining the front door declines the coach marks,
  or the relaxed tour gate would answer the X with an uninvited tour on the
  next load. Account keeps "Replay the tour". Adding a team stamps
  `onboarded_at` too, so the prompt cannot return on a page that now has
  their team.
- **`onboarding_opened` counts the PRESS, not the render.** The wizard is
  rendered on every Home load and used to count itself in `mount()`, so the
  step measured guest page loads — 201 of them in the week to 2026-08-30
  against 5 registrations, a "2.5% completion rate" that was a traffic number
  divided by a signup number with no funnel in between. The signal now rides
  `begin()`, called from the `start-onboarding` handler, guests only and once
  per browser per day (reopening the overlay is not a second signup). **Counts
  before 2026-08-30 are not comparable to counts after it** and nothing was
  backfilled: the old rows mean what they measured, and an estimate written
  into `ux_events` to make a chart continuous would be a fabricated number in
  a table read by an advisor that cannot tell the difference.
- **`onboarding_credentials_reached` splits the drop in two**, and exists
  because the week to 2026-08-31 read 225 opened against 5 registered with
  nothing between them — everyone who registers finishes (team pick 5 of 5,
  tour 5 of 5), so the entire loss sat inside three wizard steps the funnel
  could not tell apart. It is emitted at the step boundary in `next()`, guests
  only, deduped on the same session hash `begin()` uses, and it earns a case in
  a deliberately bounded enum because it is a thing that HAPPENED rather than a
  difference of two counters (which is why "slate abandoned with zero picks"
  still is not one). One case, not three: "left before we asked for anything"
  and "left at the email and password" are the two halves that call for
  different fixes, and a counter per pane would be a bar chart nobody reads.
- **A new signal reads zero for every day before it shipped**, and its
  first seven-day reading is not a seven-day number: this one read 0 beside
  163 opened two days after it went live and was filed (CFB-48) as the wizard
  losing everybody, when the wizard reaches the credentials pane on every path
  a browser can drive. The rollup now writes a zero row for every signal on
  every finished day, and the snapshot's `funnel_since` says the first day each
  total covers — read the total against that date, never against the window.
- **The device draft restores the step with a LIVE `$wire.set`, chained after
  `begin()` resolves.** The restored FIELDS are bound to elements, so a
  deferred set repaints them for free; `step` is bound to nothing — it selects
  a server-rendered pane — so the deferred set this used to do moved the
  component's state and painted nothing at all. A returning guest saw the name
  pane while the server believed they were on 'rating', and one Continue
  validated the RATING rules and landed them on the credentials form: the
  trash-talk question skipped, the retyped name unchecked, and the new
  credentials-reached signal counted for somebody who never saw the pane
  before it. Chaining matters as much as the live set: Livewire batches
  same-tick calls into one commit and `begin()` is `#[Renderless]`, which
  suppresses the render for the WHOLE commit.

## Verification pays first, then the clock runs

Email verification is deliberately LENIENT: it gates **participation** —
Pick'em actions and XP earning (bar the seeded first-team grant) — never
reading your own data. `/account` sits behind `auth` alone; the v3 lesson in
that route comment is "middleware actually applied", not "verify early".

**A private seat is not participation** (2026-09-02). `JoinGroup` seats an
unverified account in a private group: the invite code is the credential,
and the seat earns and risks nothing — picks, the Lock, the tiebreaker and
every wallet write keep their gate in their own actions, and the first-group
XP waits for the first seat taken verified. Public rooms keep the gate, since
their seats are capped and house-run. The reason is the funnel it replaced:
scan the QR, register, land back on the same invite card with a button that
refused you, then lose the invite when the verification click landed on
Home. Now the join screen parks the code beside the intended URL
(`join.auto`) and seats the reader on the way back from `register`, the
clubhouse carries the verify nudge, and the register screen names the group
off the intended URL. A seat that never verifies goes with the account at
`User::VERIFICATION_GRACE_DAYS`.

- **Verifying pays**: `Illuminate\Auth\Events\Verified` →
  `GrantVerificationReward` → one idempotent `wallet_entries` row (100 XP +
  1 Tallboy, key `email-verified`). The unique `(user_id, key)` index
  absorbs double fires; repeatable entries (future spends, weekly wins) pass
  no key. All wallet writes go through `App\Actions\GrantWalletEntry`.
- **The nudge is reward-first and ONE ROW** (`x-verify-email-callout`,
  `verify.callout.body` — a single sentence, no heading) on Home and Account —
  a stacked card there taxed the screen it was selling. Dismissable to
  SESSIONSTORAGE only, because it must return next visit; the Picks screen
  carries a non-dismissable variant (`verify.picks.body`) explaining the one
  gate verification actually holds.
- **Never-verified accounts self-destruct**: `User::VERIFICATION_GRACE_DAYS`
  (14) after signup, warned at day 11 by `cfb:verification-reminders`
  (`VerificationReminderNotification`, LOUD, stamps
  `verification_reminded_at`). `User::prunable()` refuses anyone unwarned or
  warned under `VERIFICATION_REMINDER_LEAD_DAYS` (3) ago — a mail outage
  pauses deletion rather than breaking the mail's promise — plus never
  verified accounts, never admins. Pruning rides the existing `model:prune`
  wakes; the FK-less notifications rows go in `pruning()`.
- **The app flips itself when the mail link is clicked elsewhere.** iOS
  cannot deep-link into an installed PWA, so the click always lands in a
  browser tab — the app finds out by POLLING its own database: the verify
  notice screen at 3s hot (`checkVerified` flashes and redirects, ending the
  poll), the callout at an ambient 15s (its `@if` gate is the poll's guard;
  `.visible` deliberately omitted because dismissal `display:none`s the row).
  Measured trade: Home already full-re-renders on a 30s live cadence, so the
  ambient poll is a known quantity that exists only while unverified.
- **Where the click LANDS branches on `User::hasInstalled()`**
  (`standalone_seen_at`, stamped once by the layout beacon the first time a
  session runs standalone — the only install fact a browser tab can read).
  Installed → the `/verified` off-ramp (auth layout; its one job is ending
  the tab, so `intended()` is deliberately ignored and a quiet "Continue in
  browser" stays the escape). Everyone else → Home wearing `verify.moment`,
  the flash idiom's second consumer — the redirect used to carry
  `?verified=1`, state in a URL that nothing read and an install would have
  captured. The celebration row is one-load by construction; the poll flip
  shows no celebration on purpose (no flash rides an update request — the
  chips ticking up are the app's feedback).
- **Android captured links reuse the running window**: the manifest's
  `launch_handler: navigate-existing` — two live windows of one PWA
  double-splash and fork session state.

## The install pitch waits for demonstrated interest

Install language, not bookmark language — it IS a real web-app install
(Chromium's own UI says Install; Apple's "Add to Home Screen" stays verbatim
inside the steps). The banner (`x-install-banner`, one slim row) renders for
**members only, after the tour completes** — the tour's last stop makes the
case; the banner reinforces. Guests never see it: the front door outranks the
shell. Dismissal is `$persist` to localStorage **namespaced by user id**
(`cfb.install.dismissed.{id}`): install state is a property of the DEVICE —
a new phone should hear the pitch again — and the id keeps two people on one
phone from answering for each other. No table, no cookie.

**The tour's closing stop sells NOW, and carries the how.** On a detected
phone, the card renders that browser's actual steps inline (via
`x-install-guide`, the shared per-platform steps both surfaces consume, so
the two can never teach different instructions) with a quiet
"Different browser?" link out to `/app`; undetected falls back to the
"Show me how" button. The copy is aggressive on purpose
(`tour.install.*` — "Install it. Right now.") because the steps are one
glance away, not one page away.

`/app` (get-app) focuses a confidently detected phone down to ONE platform's
steps (FxiOS before CriOS before the Safari default — every iOS browser is
WebKit wearing a badge) with a "Using a different browser?" toggle, plus a
bouncing arrow cue (`x-install-arrow`) toward the control the first step
names — only when the platform on screen is the one detection FOUND, phone
widths only, `motion-safe:` only. Cue positions are one tweakable map in
get-app: browser chrome moves between OS versions, so verify them on real
devices, not in a resized window. Two learned-on-a-real-phone facts live in
the steps: **iPhone Chrome and Firefox both tuck Add to Home Screen behind
More** on a stock share sheet, so both walkthroughs route through it; and
**Firefox's web clip ignores the `apple-touch-icon` link**, so the root
convention paths (`/apple-touch-icon.png` + sized/precomposed variants) are
real routes serving the branded PNG through `Brand` — a 404 there is a
generic gray letter tile on someone's home screen. Desktop Firefox gets the
honest line (no install there).

## The example team is the reader's team

When copy needs a school as an example ("start typing and it finds…"), use
the **reader's own first team** (`tour.search.body_team` — `:prefix`/`:team`
from their follows) or name nobody (`tour.search.body`, the skipped-picker
fallback). Never a canned school: a hardcoded example is somebody's rival,
and the pilot audience — Tennessee alumni — taught us which one. **Georgia in
particular must never appear as example or joke copy**; the only way it
reaches a screen is as the reader's own followed team. Where personalization
is not plumbed (the Search screen's empty state), the static example is
Tennessee. `GuidedTourTest` sweeps every tour line for the word.

## The pick'em vocabulary: eight words, and two that are banned

Settled 2026-08-20 and swept through the code in the same pass. These are
product words, so they mean the same thing in copy, in comments, in method
names and in tests — a screen that calls a contest a "game" makes the word
"game" useless for the thing being played on a field.

| Word | Means |
| --- | --- |
| **game** | a football game, on a field, in `games`. Nothing else, ever. |
| **picks** | a user's calls in a contest — the product's name, and the nav label |
| **slate** | one contest's set of games for one Saturday (`slates`) |
| **entry** | a user's seat and results in one contest's slate (`slate_entries`) |
| **contest** | the playable thing a group or room runs (`contests`) |
| **room** | the colloquial word for a public one-Saturday contest — the founders' own usage, kept |
| **group** | a private, season-long container of people (`groups`) |
| **lobby** | where the open contests are browsed and entered (`/lobby`) |

**"Board" is banned.** It was the paper-league word and it lost to SLATE:
`PickemVoiceTest` sweeps every pick'em Voice family for it, and the 2026-08-20
purge finished the job in the internals — `pickem:publish-slates`,
`slateGames()`, comments and PHPDoc. The Stats screen keeps the word, because
a stat leaderboard genuinely is a board.

**"Floor" is banned** for the lobby — nobody outside the code ever called it
that. `Cadence::activeSaturday()` carries the name it earned instead: the
Saturday this pick'em week is ON, which private-group deadlines ask too.
Numeric floors (the rank ladder's rung floor, palette contrast ratios,
rate-stat minimums) and the PWA's offline floor are a different word and stay.

Two more that are easy to slip: a group plays a **mode**, never "a game"
(the pivot lever, its Voice lines and the create form all say mode), and
what a reader sees on My Picks is **My Groups**, not "your games".

## Say TRENDS, not "form"

A team's recent W/L run is **trends** — `x-trend-pills`, `$glance['trend']`.
"Form" is the soccer word for it and reads as borrowed in an American football
app, the same instinct as favorite-not-favourite.

While in the neighborhood: **plural nouns read better in this copy.**
"Records, trends, next games" beats "record, form, next game" — a season is
a run of things, not one of each.

## American spelling, everywhere

**Favorite, not favourite.** This is an American football app; British spellings
read as a mistake in it. The rule covers UI copy, comments, PHPDoc, variable and
method names, tests and this file — not just what a user sees.

The word still appears in `game_odds.favorite_team_id` (the betting favorite),
so a stray "favourite" in a comment sitting next to it was the tell. Same for color/colour, center/centre,
canceled/cancelled.
