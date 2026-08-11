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

    PG     Mild           clean, still warm — never limp
    PG-13  Locker Room    the default; how the group chat actually talks
    R      Anything Goes  unfiltered, for the people who asked for it

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

## Identity: first/last name, a handle, and a content rating

Registration collects **first and last name separately**, a **handle**, and a
**content rating**; all four are editable from Account. There is no `name`
column — `$user->name` is an accessor over the two halves, which is why nothing
that printed a user had to change.

**Handle, not username.** It is the sport's own vernacular, and it sets the
expectation that this is the name you are shouted at by rather than a login
credential. Unique, and case-insensitively so: the column's
`utf8mb4_unicode_ci` collation makes the unique index reject `@Taylor` when
`@taylor` exists, which is the confusion a handle is for preventing. On edit the
rule needs `Rule::unique(...)->ignore($user->id)` or saving any other field
fails against your own row.

**Mask the handle on the CLIENT, validate on the server.** Livewire will not
overwrite a focused input — that is what stops it clobbering your typing — so a
server-side clean leaves the visible text disagreeing with the stored value
until blur. `x-mask:dynamic` corrects the character as it is typed; the rule
stays as the guarantee.

**`ContentRating` replaced `TrashTalkIntensity`** — the same axis with borrowed
vocabulary, because "Mild / Locker Room / No Holds Barred" needed explaining and
PG / PG-13 / R does not. The old names survive as SUB-labels, except the top
tier: "No Holds Barred" is wrestling jargon and is now "Anything Goes". Values
were remapped in place by the migration so nobody's setting reset. Default is
PG-13, pre-selected at registration rather than blank — an unset radio group
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

## Onboarding is one blue button, then four small screens

Home's getting-started card is the front door: guests see `Get started` and
step through name → handle → trash talk → email+password; signed-in users see
`Add your team` and go straight to the picker. Both land in the same
full-screen overlay (`livewire/onboarding.blade.php`, `fixed inset-0 z-50`
over app chrome at z-40) rather than navigating — the same reason the search
panel expands in place.

- **Credentials come LAST**, which is a conversion choice and a security one:
  an abandoned signup has no password or email to leave anywhere.
- **The device draft (`localStorage['cfb.signup']`) stores only the first
  three screens.** Two independent protections, because either alone can be
  undone by a later edit: an explicit allowlist of the three fields, AND no
  save handler on the credentials screen at all. Verify by READING storage,
  not by reading the code.
- **The draft saves from the ELEMENT that fired, never from `$wire`.** These
  bindings are deferred, so `$wire.handle` is still empty while the user types
  into it — saving from component state wrote a step behind.
- **Every step needs its own `wire:key`.** Without one Livewire morphs step
  one's input into step two's — same tag, same position — and the reused node
  kept its old binding long enough for a keystroke to land on the previous
  field. Found in the browser: typing a handle wrote to `first_name`.
- **`register()` does a FULL redirect** to `home?start=team`, not
  `navigate: true`: registering flips the whole page's auth state and every
  `@auth` region has to re-render. The redirect also means nothing client-side
  runs afterwards, which is why an authenticated load clears the draft.
- **Dismissal reuses `onboarded_at`** (guests: a session flag). Adding a team
  stamps it too, so the prompt cannot return on a page that now has their team.

## Say TRENDS, not "form"

A team's recent W/L run is **trends** — `x-trend-pills`, `$glance['trend']`.
"Form" is the soccer word for it and reads as borrowed in an American football
app, the same instinct as favorite-not-favourite.

While in the neighbourhood: **plural nouns read better in this copy.**
"Records, trends, next games" beats "record, form, next game" — a season is
a run of things, not one of each.

## American spelling, everywhere

**Favorite, not favourite.** This is an American football app; British spellings
read as a mistake in it. The rule covers UI copy, comments, PHPDoc, variable and
method names, tests and this file — not just what a user sees.

The word still appears in `game_odds.favorite_team_id` (the betting favorite),
so a stray "favourite" in a comment sitting next to it was the tell. Same for color/colour, center/centre,
canceled/cancelled.
