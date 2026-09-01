# Picks Pass 2 — the clubhouse hero, Talk as a tab, icons that upload, and a calmer overview

**Status: proposed Sep 1, 2026, after pass 1 ([picks-switcher-pass](picks-switcher-pass.md),
PRs #98–#104) merged and deployed. Handed off to a fresh session; approve there
before building. Ship as stacked, independently revertible PRs merged
bottom-up — the house pattern. No migrations, no schema, no flags.**

## Context

The founder's read after pass 1: significantly better, the clubhouses "are
starting to really take shape", but the overview "is still cluttered" and the
"All my picks" label has to go. Four asks, plus one open card:

1. Clubhouse hero: Talk moves to a subtab after Invite; the only icon button
   left in the hero is the commissioner's settings cog; the band goes light so
   an uploaded icon has something to contrast against.
2. Clubhouse: the mode brief under the hero moves under the tabs.
3. CFB-41 (group icons, PR #92, then PR #97) "has yet to succeed" in
   production — fold the fix in.
4. The overview: free rein to reach "absolute perfection" — the label and the
   layout, without the founder's steer.

### Measured facts this plan stands on (device harness, 390px, real DOM)

- Five gutter tabs fit at 390 only if cells size to content: labels at
  `text-sm font-medium` measure Slate 32.4 · Standings 64.2 · Members 59.7 ·
  Invite 34.4 · Talk 25.9 (px); the track is 352px inside; five EQUAL cells
  give 54.4px of label box, which clips Standings and Members; content-sized
  cells at `px-2` total 298px. A sixth tab ("Rules" 36.4) does not fit either
  way, so the brief becomes an accordion, not a tab.
- The overview with ONE group is 1039px tall; each section spends 60px on its
  heading + Voice line for an 87px card; the doors at the foot are 68–88px
  each; a seated reader meets switcher 24 · plate 33 · ribbon 49 · you-strip
  59 before any content.

### The CFB-41 diagnosis (read-only, vendor-verified)

PR #97 removed the ACL from the BROWSER's presigned PUT only. The app's own
copy-back (`SetGroupIcon` → `Storage::disk('r2')->put()`) still goes through
Flysystem's S3 adapter, which always sends an `ACL` (defaulting to `private`)
— there is no Flysystem option to omit it — and `throw => true` turns any R2
refusal into a 500 on the Livewire update. The checksum pin in
`config/filesystems.php` sits in the adapter's `options` slot, which the SDK
never reads, so `x-amz-checksum-crc32` still rides every PutObject
(`UploadDiskTest` is green over an inert setting). Whether the deployment even
sets `UPLOAD_DISK=r2`, `AWS_URL`, and a CORS policy that allows PUT cannot be
decided from this repo, so the fix ships with a doctor command that answers
those in one line of output. Also: `accept="image/*"` lets an iPhone hand over
HEIC, which Laravel 13's `image` rule accepts and `dimensions` then misreports
as "too small".

## Decisions

- Talk becomes the clubhouse's last gutter tab (group: Slate · Standings ·
  Members · Invite · Talk; room: Slate · Standings · Members · Talk), members
  only. `/groups/{g}/talk` stays as a 301 to `?view=talk` so every existing
  link keeps working. The pick SURFACE stays chat-free (the rule narrows from
  "group.blade.php has no conversation" to "the slate view renders none and
  `partials/pick-slate` never mounts one"); the thread mounts only on its tab.
- The hero keeps one icon button: the cog, for a commissioner with a pivot
  available (the current gate). The Talk icon and the Standings-foot Talk
  link-row go — the tab owns the door, the same reasoning that took the invite
  button out of the hero.
- The hero band is light: white with a zinc-200 border (dark: zinc-900 with a
  zinc-800 border). Every white-wash child (initials tile, kind chip, action
  button, meta line) is repainted with it. The overview's week band adopts the
  same light grammar so the two screens read as one system.
- The mode brief (blurb + `group.private.frame` / room pitch + zinger) leaves
  the hero and becomes a collapsed `x-mode-rules` accordion at the top of the
  Slate tab, carrying the full rule lines and the shared laws; the copy on the
  Standings foot is removed so the rules are stated once.
- `x-gutter-tabs` gains a `fill` variant (full-width track, cells sized to
  content and sharing the spare width, never clipping); the clubhouse uses it.
- CFB-41: ACL-free app writes through an S3 client middleware attached when
  the disk RESOLVES, checksum options moved where the SDK reads them, a legible
  upload failure, JPG/PNG/GIF/WebP only, and `php artisan cfb:uploads:doctor`.
- Overview: the design panel's verdict (below).

## PR stack (stacked, merge bottom-up; each green alone; 9 must precede 10)

| PR | branch | change |
|---|---|---|
| 1 | `gutter-tabs-fill` | `x-gutter-tabs` `fill` variant + docs rule 7 |
| 2 | `clubhouse-hero-light` | light hero band and children; cog-only actions; empty-wrapper guard |
| 3 | `clubhouse-talk-tab` | Talk tab, `pickem.talk` redirect, `group-talk` retired, rule + tests |
| 4 | `clubhouse-rules-accordion` | mode brief → `x-mode-rules` accordion atop Slate; Standings foot copy removed |
| 5 | `r2-writes-drop-the-acl` | CFB-41: resolution-time middleware, checksum config, legible failure, mime rule, doctor command |
| 6 | `picks-switcher-title` | title-weight switcher on /picks; "My groups and rooms" |
| 7 | `picks-week-band` | new light `x-week-band` replaces the ribbon + you-strip; tour order |
| 8 | `picks-ask-and-voice` | button above zinger; count on the heading row; invite row borderless; Voice trim |
| 9 | `picks-tail-doors` | "Rooms you've played" removed; History on the Results heading; How-this-works subline; teaser gated |
| 10 | `picks-one-measure` | sidecar removed; one `max-w-3xl` measure; `md:grid-cols-2`; `gap-5` |

### PR 1 — `x-gutter-tabs` `fill`

`resources/views/components/gutter-tabs.blade.php`: third variant `fill` —
track `w-full`, cells `flex-auto min-w-0 px-2` (a cell's base size is its
label, spare width is shared; nothing clips as long as the labels' sum fits,
which five do at 298px of 352px). Docblock: why `block`'s equal division cannot
hold five and why this is not a scroll. `ChromeConsistencyTest`'s gutter sweep
skips the component's own file. `docs/ui-system.md` rule 7 gains the variant
with the measured numbers. Test: `tests/Feature/Screens/GutterTabsTest.php`
(Blade::render, like `FilterMenuTest`): `fill` renders `flex-auto` cells and no
`flex-1`; `block` and `shrink` unchanged.

### PR 2 — the light hero, cog only

`resources/views/components/group-hero.blade.php`:
- root `bg-white text-zinc-900 border border-zinc-200 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100` (the docblock's "deep zinc surface" rationale rewritten: light, so an uploaded mark and the initials tile have contrast; the same grammar as every card).
- kind chip `bg-zinc-100 text-zinc-600 dark:bg-white/15 dark:text-zinc-100`; meta line `text-zinc-500 dark:text-zinc-400`.
- `resources/views/components/group-icon.blade.php` tile `bg-zinc-100 text-zinc-700 dark:bg-white/15 dark:text-zinc-100` (initials); the `<img>` unchanged.
- `resources/views/livewire/group.blade.php` actions slot: delete the Talk `<a>`; the cog becomes `bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700`; the upload label's camera scrim stays (`bg-zinc-900/60 text-white`, it sits over the mark). `line-clamp-2` on the title stays.
- **The empty wrapper trap**: `group-hero.blade.php:74` is `@if ($actions ?? false)` and a passed `ComponentSlot` is truthy even when empty, so after the Talk icon goes every plain member would render an empty `flex … gap-2` div and spend 12px of the title row on nothing. Change the guard to `@if (($actions ?? null)?->isNotEmpty())`; the member test asserts the wrapper is ABSENT.
- `resources/views/components/filter-menu.blade.php` hero variant: no change (currentColor); verify the `opacity-70` chevron reads on white.
- Docs: `docs/screens.md` "The name is the switcher" paragraph ("beside the mark and the two controls" → one control) and the hero paragraph; `.ai/rules/components-views-livewire.md` title-row budget note gains "the Talk icon left the row, giving ~44px back".
- Tests: `GroupPageTest:354-367` keeps its `Group talk` / `route('pickem.talk')` assertions in THIS PR (it renders `view=standings`, whose link-row still exists until PR 3); `GroupIconTest` "says the kind on both sides" holds; new: the band carries `bg-white` and no `bg-zinc-900` in light (source pin), the actions slot renders exactly one button for a commissioner (`cog-6-tooth` once, `chat-bubble-left-right` zero) and no actions wrapper at all for a member. No test pins `bg-zinc-900`, `text-white`, `bg-white/15` or `bg-white/10`.
- Device pass at 390/768, light AND dark (`localStorage['flux.appearance']` + reload), with an uploaded icon and with initials.

### PR 3 — Talk as a tab

`resources/views/livewire/group.blade.php`:
- `VIEWS` (:87) += `talk`; `tabs()` (a `#[Computed]`, :738) appends `'talk' => 'Talk'` when `$this->isMember` (after Invite for a group, after Members for a room); `normalizedView()` folds `talk` → `slate` for a non-member (the `invite`/lobby fold's shape; it runs in `mount()` after `$this->group` is set and in `updatedView()`); the strip switches to `variant="fill"`.
- New branch `@elseif ($view === 'talk')` → `@if ($this->isMember) <livewire:conversation :topic="$group" :key="'talk-group-'.$group->id" /> @endif` — not lazy (the tab tap is the intersection; the exclusive branch re-mounts fresh per entry); the conversation renders its own `talk.subheading.group` line. `countSlateEntry()` needs no change.
- Remove the Standings-foot Talk link-row (:1401-1406).
- `resources/views/partials/pick-slate.blade.php:235-239` "Talk it over" → built off `$slate->contest->group` (the partial has no `$group`; `slate-builder.blade.php:865` includes it too): `route($g->isRoom() ? 'pickem.room' : 'pickem.group', [$g, 'view' => 'talk'])`.
- `routes/web.php:329`: `Route::get('groups/{group}/talk', fn (Group $group) => new RedirectResponse(route($group->isRoom() ? 'pickem.room' : 'pickem.group', [$group, 'view' => 'talk']), 301))->name('pickem.talk')` — the plain `RedirectResponse` idiom the legacy redirects at :350-362 use and explain. Same middleware group. `resources/views/livewire/group-talk.blade.php` deleted. `app/Support/Navigation.php:147` keeps `pickem.talk` in the section routes.
- `join()` (:832) and `leave()` (:976) already `unset` `members`/`isMember`/`isCommissioner`; add `$this->tabs` to both.
- Voice: retire `talk.door.hint` (no render site) from `Voice::LINES` and the `PickemVoiceTest:116` row — same PR as the link-row removal.
- Rule (record-rule, glob `app/Actions/PostToConversation.php,app/Actions/DeleteConversationPost.php,resources/views/livewire/conversation.blade.php`): "The clubhouse hosts the group thread on its Talk tab; the pick SURFACE stays chat-free — `partials/pick-slate` never mounts a conversation, and the slate view renders none; `/groups/{g}/talk` redirects to `?view=talk`." Mark the superseded sentence in `.ai/rules/livewire.md:15` with the house's supersede-in-place marker; never rewrite a recorded body.
- Tests (`tests/Feature/ConversationTest.php`): :414-423 source pin → `partials/pick-slate.blade.php` contains no `<livewire:conversation`; new behavioral pins: `Livewire::test('group', …)` on the slate view does not render `talk.subheading.group`, `->set('view', 'talk')` does; a non-member's `?view=talk` folds to `slate`; a lobby outsider sees no `group-tab-talk`. :515-540 → `get(route('pickem.talk', $group))` asserts a 301 to the clubhouse `?view=talk` (keep the test's `config()->set('cfb.pickem_open', true)`), a stranger following it is forbidden on a private group; the member's thread assertions (`The Loud Ones`, `First flag planted.`) move onto `test('group', …)->set('view', 'talk')`. `GroupPageTest:354-367` drops `Group talk` / `route('pickem.talk')` here; :371 count → 5 (group) and the room test → 4; :586-603 renamed "gives a room four stops and a group five"; :653-667 `assertDontSee('Room talk')` → `assertDontSeeHtml('wire:key="group-tab-talk"')`.
- Docs: `docs/screens.md` "One strip, four stops" → five; Talk paragraph.

### PR 4 — the brief becomes the rules accordion

- `resources/views/components/mode-rules.blade.php`: an optional `$slot` rendered inside the payload after the rule list, guarded `@if ($slot->isNotEmpty())` so the lobby and picks-how callers render no empty div; a `pitch` prop (nullable string) that REPLACES the mode blurb on the identity row (a room deals its flavor's card); and a `clamp` prop that renders the identity line `line-clamp-2` instead of `truncate` — `LobbyFlavorTest:195-199` exists precisely because a truncating shelf line cannot carry the pitch.
- `group.blade.php`: delete the two blocks at :1141-1180 (private brief, room pitch); render the accordion at the TOP of the Slate branch (:1253), ABOVE the `@if ($this->slate?->isPublished())` fork and ungated on membership — a group with no slate (`PickemGroupsTest:219-225`) renders the else branch, and the frame line must still be in its DOM. `<x-mode-rules :mode :games :pitch="$roomFlavor?->blurb($games)" clamp>` collapsed; slot = private: `group.private.frame` as a `text-micro` line; room: the quoted zinger; then `@include('partials.pickem-laws')`. Keep the accordion OUTSIDE the sidecar grid wrapper (DesktopChromeTest pins `'sidecar' => $slateSidecar` and the grid string). Remove `<x-mode-rules>` from the Standings foot (:1392-1399).
- Tests: `PickemGroupsTest:222-233` and `LobbyFlavorTest:195-199` still pass (x-show keeps the lines in the DOM on the default slate view; neither fixture can flip `opensToStandings()`); new: the accordion renders exactly once (`aria-controls="mode-rules-…"` count 1), the laws render on the clubhouse, `assertDontSee` of the frame line on the Standings view render, the identity line carries `line-clamp-2` on the clubhouse and `truncate` on the lobby. `GroupPageTest:364` `Triple Option` is the hero meta.
- Docs: `docs/screens.md` hero/brief paragraphs (:889-899, :923-929, :964-967).

### PR 5 — CFB-41: writes R2 can accept, and a doctor

- **Attach at disk RESOLUTION, never at boot.** `FilesystemManager::resolve()` consults `customCreators` before the built-in creator and `createS3Driver()` is public, so in `AppServiceProvider::register`: `Storage::extend('s3', function ($app, array $config) { $disk = $this->createS3Driver($config); if ($config['no_acl'] ?? false) { R2Writes::attach($disk->getClient()); } return $disk; })`, with a plain boolean `'no_acl' => true` on the `r2` disk (config-cache safe; unknown disk keys flow through `formatS3Config` and the SDK resolver ignores extras). The driver stays `s3`, so Livewire's `isUsingS3()` is untouched. New `app/Support/R2Writes::attach(S3Client $client)`: idempotent (`$list->remove('r2.no-acl')` then `appendInit(Middleware::mapCommand(function (CommandInterface $c) { unset($c['ACL']); return $c; }), 'r2.no-acl')` — `mapCommand` calls `$handler($f($command))`, so the closure must RETURN the command). One seam for every app write (SetGroupIcon, the account photo, Brand, Filament). The init middleware also runs on the presigned PUT (`Aws\serialize` walks the whole handler list) — a harmless no-op beside `R2SignedUploadUrl`. A boot-time attach would never be on the client the tests use (`phpunit.xml` sets `UPLOAD_DISK=public`; `useR2()` flips config after boot) and would not survive `forgetDisk()`.
- `config/filesystems.php` r2: move `request_checksum_calculation` / `response_checksum_validation` to the TOP level of the disk array (they reach `new S3Client($s3Config)` through `formatS3Config`; `S3Client::getConfig()` reads them and `ApplyChecksumMiddleware` honors them); comment why `options` is the wrong slot. Add `'no_acl' => true`.
- `app/Support/ImageUpload.php` rules: `['bail', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:…', 'dimensions:…']` — `bail` is what stops "too small" riding along with the mime message. Message "Use a JPG, PNG, GIF or WebP." (plain); `x-image-file-input` `accept` narrowed to those types.
- `group.blade.php` `updatedIconFile()` (:900-916) and the account photo write: the existing `catch (NotGroupCommissioner)` (which `abort(403)`s) stays FIRST; add `catch (\Throwable $e)` after it → `report($e)` + `addError('iconFile', Voice::line('groups.icon.failed'))`; `$this->iconFile = null` on both paths. Three registers, pg13 draft: "The icon didn't make it to the shelf. Try again in a minute — if it keeps failing, the storage side is the problem, not your picture." Add `groups.icon.failed` to the `PickemVoiceTest` dataset.
- New `app/Console/Commands/UploadsDoctorCommand.php` (`cfb:uploads:doctor [--probe] [--force]`), the `DoctorCommand` shape (`#[Signature]`/`#[Description]`, `self::FAILURE` on any failing line): prints the resolved upload disk, the Livewire temp disk and `isUsingS3()`, whether AWS_URL/AWS_BUCKET/AWS_ENDPOINT are set (names, never values), the client's `getConfig('request_checksum_calculation')`, and whether `r2.no-acl` is in `(string) $client->getHandlerList()`. `--probe` puts/reads/deletes a 12-byte object and HEADs its public URL, reporting each step — it WRITES to whatever bucket the env names, and `docs/operations.md:311-316` warns a local .env can hold the live keys, so it prints the disk and bucket NAME and refuses outside production unless `--force`. `docs/operations.md` R2 section: the ACL-on-app-writes paragraph and the doctor.
- Tests (`tests/Feature/Storage/UploadDiskTest.php`): replace the inert `options.request_checksum_calculation` assertion with `Storage::disk('r2')->getClient()->getConfig('request_checksum_calculation') === 'when_required'`; new: with a `MockHandler` + `Middleware::history` on the resolved client (the `useR2()` bucket-shaped dummy config, no network), `Storage::disk('r2')->put('probe.txt', 'x')` builds a PutObject with no `ACL` and no `x-amz-checksum-crc32` header, and the middleware is present after `forgetDisk('r2')` + re-resolution; `SetGroupIcon` reports the Voice line, not a 500, when the disk throws; `ImageUpload::rules()` refuses a `.heic` with the mime message and nothing else; a doctor test asserting the report lines against `Storage::fake('public')` and the `--probe` refusal without `--force`.
- Rule (record-rule, glob `app/Providers/AppServiceProvider.php,app/Support/R2SignedUploadUrl.php,config/filesystems.php,config/livewire.php`): the two halves of "no ACL" and where the checksum keys live.

## The overview (/picks) — the design panel's verdict, adopted

Four independent redesigns were judged by three lenses (lost reader, design
system, engineer); the "fewest rows" proposal won unanimously (63/58/55/52),
with grafts: a title-weight switcher, one rewritten definition line, the
button above the zinger, the Lobby teaser gated to the first run, History on
the Results heading row, non-nesting tour anchors. Rejected on evidence, so
the next pass does not re-litigate: the switcher in the plate's actions slot
(silences the screen's name and makes the `switcherOf()` test slice
vacuous); Home's `x-next-up` on /picks (needs a PickemPulse refactor, a second
groups query, and its rung-8 line repeats the pinned Lobby sentence); the
plate → gutter swap; excluding the hero's group from My Groups; capping the
card lists; "Overview", "All my seats" and "Everywhere I play" as the label.

### What the reader sees (390px, 3 groups + 1 room, picks due)

```
My groups and rooms ▾                     25   title-weight switcher (hero variant), start-aligned
This week | Results                       33   plate, actions slot empty
┌ Week 1 · Sep 4–6      First kick Sat 3:30pm ┐   LIGHT week band (new x-week-band)
│ You @taylor  Rank · XP · Tallboys · Wins     │   row 1 data-tour=week · row 2 data-tour=balance
└──────────────────────────────────────────────┘
Needs your picks               and 2 more below    heading row, count on the right
[mode tile: The Noon Kick · 6 of 15 · kicks Sat 3:30 · BUTTON · zinger]   button top ≈ 306px (was 453)
My Groups                              Start a group   data-tour=seats
[card][card][card]
Have an invite code? ▾                    24   borderless text row, same x-data
Week 1 Contests
<picks.contests.subheading — the screen's ONE definition, rewritten>
[room card]
▸ The Lobby — 3 public rooms open this Saturday      data-tour=room, no teaser line
▸ How this works — Scoring, ranks, and what a room costs.   data-tour=how
```

Stack 1138px (was ~1400); the measured one-group case goes 1039 → 848
(−18%); container species 6 → 4; Voice lines on the seated tab 5 → 2; the
first button rises 147px. Root seam `gap-5` (the clubhouse's).

### Decisions, block by block

- **Trigger label**: `'label' => 'My groups and rooms', 'menuLabel' => 'All my groups and rooms'` at `group-switcher.blade.php:56` — the trigger is the screen's title (both container nouns, the possession, no third naming of "My Picks"); the menu row must read as everything because it sits above the "My Groups" group heading. "All my picks" dies at its one source. Sentence case on purpose: "My Groups" title-cased is the section heading below. The two strings are distinguishable in an ordered assertion (capital M).
- **Switcher on /picks**: `variant="hero"` (text-xl, start-aligned, `line-clamp-2`), drop `items-center`; still above the plate (rule 8's exception is kept and reworded). Same first row as the clubhouse.
- **`x-week-band` (new, light)** replaces the dark `x-week-ribbon` (deleted; /picks is its only caller) and the blue you-strip call: row 1 = dateline + the three clock branches verbatim, carrying `data-tour="week"` on ITS OWN element; row 2 = `<x-you-strip variant="bare" data-you-strip data-tour="balance">` (new prop: `panel` = today's blue tile, the clubhouse default; `bare` = keeps the `flex items-center gap-4 py-3` row and the four columns, drops the border, fill and px — the band supplies px-4). The anchors are internal to the band because one root attribute bag cannot carry two, and `data-you-strip` stays on the strip element (`PickemHomeTest:1176` and :825 pin its presence/absence). Props `entry`, `clock`, `name`, `stats`; a null name renders row 1 alone (first run keeps `data-tour="week"` and no `data-you-strip`). One row from `md`. Surface `bg-white border-zinc-200 dark:bg-zinc-900 dark:border-zinc-800`. `ribbonClock()` → `weekClock()` (its comment at pickem-home:263 too). Note for the implementer: `x-plate`'s root carries no attribute bag, so a `data-tour` on it is silently dropped and the stop steps over itself.
- **Needs your picks**: heading row gains "and N more below" on its right; `lobby.needs.subheading` retired; inside the hero the button moves ABOVE the zinger (fact → action → flavor). Same tile, palette, progress, clock. The hero and the heading stay inside `DesktopChromeTest:328-345`'s 1500-char window.
- **My Groups**: unchanged cards, `picks.groups.subheading` retired (the heading, the menu group and every card already say it). The hero's group stays in the list — "My Groups" means every seat.
- **Invite code**: borderless semibold text row with the rotating chevron, `-my-2 py-2` for a 40px hit area; `x-data` byte-identical (`{ open: true }` is a pin); `groups.join.subheading` retired (the field's plain description is the instruction). Still unconditional.
- **Week N Contests**: `picks.contests.subheading` kept and rewritten ×3 to carry the contrast: pg "Public rooms, one Saturday each. Your groups up there run all season." / pg13 "Public rooms, one Saturday each — win it or wait for next week. Your groups up there run all season." / r "Public rooms: one Saturday, one verdict, then they're gone. Your groups up there are stuck with you all season." No right-hand item on the heading.
- **Lobby door**: `partials/lobby-door` gains `$pitch` (default true; false at the section foot) so `lobby.teaser.zinger` ("No group? No problem…") renders only on the first-run include where it is true. One door, one read, one `data-tour="room"`. Record in its docblock that PickemPulse's `picks.next.join` line contains the pinned "N public rooms open this Saturday" sentence, so a future `x-next-up` on /picks would double the count.
- **Tail**: "Rooms you've played" removed (`picks.rooms.past` retired); "Season history" becomes a `History` text door on the Results "Last week" heading row (Home's heading-door idiom); "How this works" stays a full dashed door (the tour needs a box) with the subline "Scoring, ranks, and what a room costs." (closes a filed follow-up).
- **Desktop**: the lg sidecar wrapper goes; the personal branch is one measure `mx-auto flex w-full max-w-3xl flex-col gap-5` (unprefixed, so the lg-cap sweep in `DesktopChromeTest:201-224` cannot trip); both card grids `grid gap-3 md:grid-cols-2`. Nothing moves on a phone.
- **Tour**: `Tours::PICKS` becomes `['week', 'balance', 'seats', 'room', 'how']` (monotonic in source; the two band rows are siblings). No tour copy changes — all four you-strip columns survive, "the switcher up top" is still true.
- **Results**: switcher + plate → payoff → "Last week" + History door → rows → ladder → How this works. No band on Results.
- **Voice net**: 4 keys retired (`lobby.needs.subheading`, `picks.groups.subheading`, `groups.join.subheading`, `picks.rooms.past`), 1 rewritten, 1 new (`groups.icon.failed`, PR 5). `PickemVoiceTest`'s dataset lists only the first two retired keys (:92, :123) — remove those rows.
- **Pins the panel missed**: `PickemHomeTest:532` `assertDontSee("Rooms you've played")` goes vacuous once the door is gone — repoint it at "the week tab links History nowhere; Results does" rather than deleting it.

### Overview PRs

| PR | branch | change |
|---|---|---|
| 6 | `picks-switcher-title` | hero variant on /picks; label + menuLabel; docblock; `PickemHomeTest:1407` order, `GroupPageTest` ×5 (:405, :429, :438, :439, :462 take `All my groups and rooms`), `FilterMenuTest:83` comment; ui-system rule 8 wording + :236; screens.md item 1; picks-switcher-pass.md:22/57/83. `switcherOf()` gains `expect($switcher)->not->toBeEmpty()` (house rule: a helper slicing HTML by a marker asserts the slice) |
| 7 | `picks-week-band` | new `x-week-band`; `x-you-strip` `variant`; delete `week-ribbon`; anchors move; `Tours.php:42` + `PicksTourTest:40` (and add `week` to its anchor loop); `weekClock()`; PickemHomeTest "under the week ribbon" rename; extend the one-Seats-read pin (:1345-1372) to assert the band rendered; screens.md items 2 + :684 |
| 8 | `picks-ask-and-voice` | button above zinger; count onto the heading row; invite row borderless; retire the three keys + rewrite `picks.contests.subheading`; `PickemHomeTest:359-360`, :703; PickemVoiceTest rows; components.md wording ("ONE definition, under Week N Contests") |
| 9 | `picks-tail-doors` | remove "Rooms you've played" + `picks.rooms.past`; History door on the Results heading; How-this-works subline; `$pitch` on the lobby door; `PickemHomeTest:188`, :440-465 (repoint `route('pickem.history')` to the Results render), :532, :840; screens.md:733; picks-switcher-pass.md follow-ups |
| 10 | `picks-one-measure` | delete the sidecar wrapper; the capped measure; `md:grid-cols-2`; gap-5; `DesktopChromeTest:226-243` rewritten (one measure exists; `seats` still precedes the invite in source), :245-258 prose (the comment string `THE LADDER belongs to Results` survives verbatim after the invite), :279 dataset → `grid gap-3 md:grid-cols-2` |

Rules/docs touched by the overview: `docs/ui-system.md` rule 8 + :236;
`docs/screens.md` items 1, 2, :660, :671, :684, :733; `.ai/rules/components.md`
— do NOT rewrite the recorded rule's body: mark the two stale phrases
("centered above the plate", "each section's definition line is Voice") with
the supersede-in-place marker and record the new text through `record-rule`
(the two new disciplines: one definition per screen, spent on the noun the
reader lacks; one container treatment per idea). `docs/plans/picks-switcher-pass.md`
follow-ups close. This document gains a Voice manifest and a move ledger as
the PRs land.

## Verification (per PR, in order)

1. `php artisan test --compact --filter=` the touched files, then the full suite before each merge.
2. `vendor/bin/pint --dirty --format agent`; `npm run build` after any Blade.
3. Device harness at 390/768, light and dark (`localStorage['flux.appearance']` then reload), logged in through `/__device/act-as/1`: `/groups/1` (hero with initials and with an uploaded icon; five tabs; the Talk tab; the rules accordion open and closed), `/contests/{room}` (four tabs), `/picks` and `/picks?view=results`. `scrollTo({left:999}); window.scrollX === 0` in every frame.
4. `php artisan cfb:uploads:doctor` locally (public disk) and, after deploy, on Laravel Cloud with `--probe` — the founder runs the latter; its output decides whether AWS_URL / UPLOAD_DISK / CORS still need setting in the Cloud console.
5. Break-it-back: remove `no_acl` from the r2 disk and watch the PutObject pin red; give `tmp-for-tests` the `no_acl` key in `LivewireBucketUploadTest` and confirm the presigned PUT still signs no ACL; put the accordion back inside the published fork and watch `PickemGroupsTest:222` red; restore `@if ($actions ?? false)` on the hero and watch the empty-wrapper pin red.
6. Overview device pass with a reader in three groups and a room (a scratch user seeded through the test fixtures): the button top lands near 306px at 390; `data-tour="week"` and `data-tour="balance"` are sibling boxes; the Results tab shows no band; `/picks` light and dark at 390, 768 and 1280.
