# Finish the Filament Admin Panel

Planned 2026-08-28. Scope decisions settled with the owner; nothing here is
open for re-litigation unless marked as a risk at the bottom.

## Context

The panel (Filament v5.7.5, path `/admin`, own Vite theme) has three modal-only
resources (Workbook, Gameday, Team Branding), four pages, and a stock
dashboard. The goals:

1. **Compact, condensed navigation** with a Flux-dashboard feel.
2. **Resources for all core models** — strategic tabbed layouts, KPI stat
   tiles, relation managers, UX-friendly infolists, and custom
   `getHeading()`/`getSubheading()` blades. Record view pages should read like
   a store-detail dashboard: big identity heading + status badges, an
   icon-attribute meta row, tabs, KPI cards with comparison sublabels, charts.
3. **Robust user management** — edit profiles, manual verify, admin toggle,
   delete, impersonate.
4. **Finished dashboard** — user counts by verified/onboarded/etc., top-10 bar
   charts (most-followed teams, biggest groups).
5. **A way OUT of the panel.** The installed PWA has no browser chrome, so
   `/admin` is currently a dead end — this is the product's own "every dead
   end keeps a built-in exit" rule applied to the panel.

**Owner decisions (settled):** full scope — pick'em + sports reference +
people/content + engagement; compact grouped sidebar collapsible to an icon
rail; Team Branding is ABSORBED into a full Team resource; user management
gets all four powers; no new composer dependencies (impersonation is custom).

## Verified facts this plan rides on

- `route('home')` exists (`routes/web.php:21`). `Cadence::currentSaturday()`
  and `Cadence::slateDeadline()` are static and real.
  `CfbCalendar::currentYear()` is real — never hardcode a season.
- Action signatures (writes go through `app/Actions`, always):
  `RemoveGroupMember::handle(User $actor, Group $group, User $member)`,
  `GrantWalletEntry::handle(User $user, int $xp, int $lattes, string $reason, ?string $key = null)`,
  `DeleteConversationPost::handle(User $user, ConversationPost $post)`.
- `LiveState::people()` / `LiveState::groups()` are `private`
  (`app/Support/LiveState.php:237` / `:208`) — promote to `public` for widget
  reuse; the `/ops/telemetry` payload shape does not change.
- The panel runs Filament's `AuthenticateSession` (extends Illuminate's): the
  session key is `password_hash_web` and the framework stores
  `getAuthPassword()` raw — the impersonation hash-swap below stores exactly
  the canonical value, no compatibility branch involved.
- `users.admin` is deliberately NOT in `#[Fillable]` → `forceFill` is the only
  sanctioned write path. `User->name` is an accessor — sort/search the real
  `first_name`/`last_name` columns.
- The panel theme `@source`s ONLY `app/Filament/**` and
  `resources/views/filament/**` (pinned by `PanelThemeTest`); Flux is
  unavailable in the panel; `npm run build` is mandatory after Blade/theme
  changes (the panel 500s without a built manifest).
- ESPN-owned PKs are non-incrementing (Team/Game/Conference/Venue) — no
  Create actions on those resources. Team→conference goes through
  `team_seasons` for `CfbCalendar::currentYear()` (there is no
  `teams.conference_id`). NEVER query or eager-load `game_drives`
  (306 KB/row average).
- Enum-ish string columns sort via `orderByRaw("field(...)")`. A json-cast
  column's state renders as a LIST unless collapsed with
  `->state(fn (Model $r) => ...)` (WorkbookResource precedent).
- Data volumes: 11 users (pilot), 856 teams, 5.8k games, 35k athletes,
  6.2k articles, 4.7k standings, 9.7k rankings.
- House-style template: `app/Filament/Resources/Workbook/WorkbookResource.php`.
  Test patterns: `tests/Feature/Admin/WorkbookBoardTest.php` (TestAction modal
  mounting, DB::listen query ceilings), `PanelThemeTest.php`.

---

## Phase 1 — Navigation, theme, exit

### `app/Providers/Filament/AdminPanelProvider.php`

All APIs verified against vendor v5.7.5:

```php
use Filament\Actions\Action;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;

->sidebarCollapsibleOnDesktop()
->sidebarWidth('15rem')            // stock 20rem; the single biggest density win
->collapsedSidebarWidth('3.5rem')
->maxContentWidth(Width::Full)
->navigationGroups([
    NavigationGroup::make('Pick\'em')->icon(Heroicon::OutlinedTrophy),
    NavigationGroup::make('Community')->icon(Heroicon::OutlinedUsers),
    NavigationGroup::make('College Football')->icon(Heroicon::OutlinedAcademicCap),
    NavigationGroup::make('Content')->icon(Heroicon::OutlinedNewspaper)->collapsed(),
    NavigationGroup::make('Work')->icon(Heroicon::OutlinedClipboardDocumentList),
    NavigationGroup::make('Configuration')->icon(Heroicon::OutlinedCog6Tooth)->collapsed(),
    NavigationGroup::make('Operations')->icon(Heroicon::OutlinedWrenchScrewdriver)->collapsed(),
])
->navigationItems([
    NavigationItem::make('Pulse')
        ->url('/pulse', shouldOpenInNewTab: true)
        ->icon(Heroicon::OutlinedChartBar)
        ->group('Operations')
        ->sort(9),
])
->userMenuItems([
    Action::make('backToApp')
        ->label('Back to app')
        ->icon(Heroicon::OutlinedArrowUturnLeft)
        ->url(fn (): string => route('home')),
])
->renderHook(
    PanelsRenderHook::TOPBAR_START,
    fn () => view('filament.partials.back-to-app'),
)
```

- **No `->spa()`** — the exit and the Pulse link cross into the Flux front-end,
  and wire:navigate across two asset bundles is the classic breakage.
- Group icons are functional: with `sidebarCollapsibleOnDesktop()`, the
  collapsed rail renders group icons as dropdown triggers.
- Pulse is currently an orphan admin surface; the nav item adopts it.

**Taxonomy** (existing pages/resources re-homed by editing their
`$navigationGroup`/`$navigationSort`; the Team Branding nav entry dies in
Phase 5):

| Group | Items (sort order) |
|---|---|
| *(ungrouped)* | Dashboard |
| Pick'em | Groups (1), Slates (2) — Contests have no nav entry |
| Community | Users (1), Conversation Posts (2), Wallet Ledger (3) |
| College Football | Games (1), Teams (2), Conferences (3), Seasons (4) |
| Content | Articles (1), Athletes (2), Coaches (3), Venues (4) |
| Work | Workbook (1), Board (2), College GameDay (3) — unchanged |
| Configuration | App Branding (1), Pick'em Settings (3) |
| Operations | Sync Health (1), Pulse link (9) |

### `resources/css/filament/admin/theme.css`

Append a density block below the pinned lines (do NOT touch the `@source`
lines or the vendor import — `PanelThemeTest` pins them). Selectors verified
against `vendor/filament/filament/resources/css/components/sidebar.css`:

```css
/* Density: closer to a compact dashboard rail. Only overrides — never a fork
   of Filament's sheet. */
@layer components {
    .fi-sidebar-header { @apply h-14; }
    .fi-sidebar-nav { @apply gap-y-4 px-3 py-4; }
    .fi-sidebar-nav-groups { @apply gap-y-4; }
    .fi-sidebar-group-btn { @apply p-1.5; }
    .fi-sidebar-group-label { @apply text-xs leading-5; }
    .fi-sidebar-item-btn { @apply px-2 py-1.5; }
    .fi-sidebar-item-label { @apply text-[0.8125rem]; }
    .fi-sidebar-database-notifications-btn { @apply px-2 py-1.5; }
}
```

### `resources/views/filament/partials/back-to-app.blade.php`

The PWA-safe exit: a persistent chip at `TOPBAR_START` (the topbar renders on
phone AND desktop), plus the user-menu item above as the second door. Plain
anchor — no wire:navigate; this leaves the panel.

```blade
<a href="{{ route('home') }}"
   class="me-2 inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-medium
          text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5">
    <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="h-4 w-4" />
    <span class="hidden sm:inline">Back to app</span>
</a>
```

(`<x-filament::icon>` renders through Filament's own pipeline, so the panel's
`DisableBladeIconComponents` middleware does not affect it. The blade lives in
the `@source`d tree so its Tailwind compiles.)

### Tests — `tests/Feature/Admin/PanelNavigationTest.php`

Chip present in `/admin` HTML with the `route('home')` href; navigation group
registry order via panel introspection (`Filament::getPanel('admin')`);
sidebar collapsible + `15rem` width; Pulse item; non-admin still barred.

---

## Phase 2 — Dashboard

`app/Filament/Pages/Dashboard.php` extends `Filament\Pages\Dashboard`; the
provider swaps it into `->pages()` and drops `AccountWidget` +
`FilamentInfoWidget` from `->widgets()`. `getColumns(): 2`.

Widgets (`app/Filament/Widgets/`, discovered — unlike the SyncHealth five,
which keep `$isDiscovered = false` and stay page-scoped — and all
`$pollingInterval = null`):

- **`UserFunnelStats`** (StatsOverview, full width) — reuse
  `LiveState::people()` (made public): users / verified / onboarded / admins /
  push_people. Widget-local counts (kept out of LiveState so the telemetry
  payload never moves): textable
  (`sms_opt_in` + `phone_verified_at` non-null), installed
  (`standalone_seen_at` non-null). Comparison sublabels ("82% of accounts");
  a zero-user state renders factual copy, never fabricated percentages.
- **`EngagementStats`** (StatsOverview, full) — groups by kind
  (`LiveState::groups()`), contests by mode (one grouped query, rendered as
  "3 classic · 1 woodshed"), picks this Saturday
  (`Cadence::currentSaturday()`), wallet XP/latte totals.
- **`TopTeamsChart`** (bar, horizontal so team names read):

  ```php
  protected function getData(): array
  {
      $teams = Team::withCount([
          'followers',
          'followers as favorites_count' => fn ($q) => $q->where('team_follows.position', 1),
      ])->orderByDesc('followers_count')->having('followers_count', '>', 0)->limit(10)->get();

      return [
          'labels' => $teams->map(fn (Team $t) => $t->abbreviation ?? $t->placeName())->all(),
          'datasets' => [
              ['label' => 'Follows', 'data' => $teams->pluck('followers_count')->all(),
               'backgroundColor' => $teams->map(fn (Team $t) => $t->accentColor() ?? '#9ca3af')->all()],
              ['label' => 'Favorites', 'data' => $teams->pluck('favorites_count')->all(),
               'backgroundColor' => '#d1d5db'],
          ],
      ];
  }

  protected ?array $options = [
      'indexAxis' => 'y',
      'scales' => ['x' => ['ticks' => ['precision' => 0]]],
  ];
  ```

  `team_follows.team_id` is indexed for exactly this query.
- **`TopGroupsChart`** — horizontal bar, `Group::withCount('members')` top 10,
  brand primary as the single series color.
- **`PicksTrendChart`** (line, full) — picks per Saturday for
  `CfbCalendar::currentYear()` (picks → slate_games → slates grouped by
  `slates.saturday`). Unplayed weeks are absent, not zero-filled.

**Tests — `DashboardTest.php`:** widget classes tested directly (widget
content is never in the page's HTML — house rule); chart `getData()` labels /
order / colors with pinned fixtures; the dashboard loads for an admin, 403s
for a non-admin, and no longer renders the Filament info widget.

---

## Phase 3 — User management (flagship; the template for every FULL resource)

### Model edit — `app/Models/User.php`

Add `picks()` and `slateEntries()` hasMany (the FKs and `user_id` indexes
already exist; the relations never did).

### `app/Filament/Resources/Users/UserResource.php`

Pages List/View/Edit; nav Community/1; global search on
`first_name/last_name/handle/email` (real columns — `name` is an accessor).

- **Table:** avatar (`ImageColumn` via `avatarUrl()`), name
  (`formatStateUsing` the accessor; sort by `last_name` then `first_name`;
  search both columns), handle, email (+ verified `CheckBadge` icon),
  admin/onboarded/installed `IconColumn::boolean()` via the `hasOnboarded()` /
  `hasInstalled()` helpers, content_rating badge (`ContentRating::label()`),
  created_at since, desc default. Filters: verified/admin/onboarded
  ternaries (nullable-attribute form), content_rating select.
- **Infolist tabs** (`Filament\Schemas\Components\Tabs`):
  - *Profile* — handle, email + `email_verified_at`, phone +
    `phone_verified_at`, timezone, content_rating badge with `subLabel()`
    helper text, created_at.
  - *Wallet & activity* — XP/Lattes via `walletTotals()`, pick record
    (win/loss/push aggregate in `->state()`), entries won, beat_bear count.
  - *Notifications* — newsletter/pickem/sms opt-ins with their stamps, push
    device count, verification_reminded_at.
  - *Lifecycle* — onboarded_at, tour_completed_at, standalone_seen_at, and a
    prune-clock warning entry visible only while unverified (grace is 14
    days).
  `->placeholder('—')` everywhere a value can be null — a display
  placeholder, never a data default.
- **Edit form:** `#[Fillable]` columns only. Avatar
  `FileUpload->disk(config('cfb.upload_disk'))->directory('avatars')` with
  **no `->visibility('public')`** (R2 rejects object ACLs).

### The shared heading pattern

`resources/views/filament/partials/record-heading.blade.php` — one
parameterized partial, so 15 resources don't get bespoke headers:

```blade
@props(['image' => null, 'initials' => null, 'title', 'badges' => [], 'meta' => []])
<div class="flex items-center gap-4">
    @if ($image)
        <img src="{{ $image }}" alt="" class="h-14 w-14 rounded-xl object-contain" />
    @elseif ($initials)
        <div class="flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-lg font-bold text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $initials }}</div>
    @endif
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <span class="truncate text-2xl font-bold tracking-tight">{{ $title }}</span>
            @foreach ($badges as $badge)
                <x-filament::badge :color="$badge['color'] ?? 'gray'">{{ $badge['label'] }}</x-filament::badge>
            @endforeach
        </div>
        <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
            @foreach ($meta as $item)
                <span class="inline-flex items-center gap-1">
                    <x-filament::icon :icon="$item['icon']" class="h-4 w-4" />{{ $item['label'] }}
                </span>
            @endforeach
        </div>
    </div>
</div>
```

`ViewUser::getHeading()` returns
`view('filament.partials.record-heading', [...])` (a `View` is `Htmlable`,
which `getHeading()` accepts) — avatar or initials, name, badges
(Admin/Verified/Unverified), meta (handle, email, joined). Game gets one
extra partial, `record-heading-matchup.blade.php`, for the two-logo score
variant.

### KPI header widget

`app/Filament/Resources/Users/Widgets/UserStats.php` (StatsOverview,
`$isDiscovered = false`, `public ?User $record = null`): XP, Lattes, Picks
(win-rate sublabel only when graded picks exist), Groups (commissioner-of
count). Wired in `ViewUser`:

```php
protected function getHeaderWidgets(): array
{
    return [UserStats::make(['record' => $this->getRecord()])];
}
```

### Actions on `ViewUser`

- **Verify email** — visible while unverified; calls
  `$record->markEmailAsVerified()`, which fires `Verified` → the existing
  idempotent keyed XP grant listener. The listener IS the doorway; the modal
  copy says the reward lands.
- **Toggle admin** — `->requiresConfirmation()`, hidden when
  `$record->is(auth()->user())`;
  `$record->forceFill(['admin' => ! $record->admin])->save()` with a comment
  naming the deliberate non-fillability.
- **Delete** — new `app/Actions/DeleteUser.php`: refuse self; delete
  `$target->notifications()` by hand (mirrors `User::pruning()` — the morph
  rows have no FK), then `$target->delete()`; FK cascades cover picks,
  entries, memberships, follows, wallet, posts. Modal copy enumerates the
  cascade. Re-verify the `group_members` cascade in migrations before
  shipping.
- **Impersonate** — below.

### Impersonation (custom, no package)

`app/Actions/ImpersonateUser.php`:

```php
public function handle(User $admin, User $target): void
{
    // Guards are the API: never self, never another admin, never nested.
    abort_unless($admin->isAdmin(), 403);
    abort_if($target->isAdmin() || $admin->is($target), 403);
    abort_if(session()->has('impersonator_id'), 403);

    session()->put('impersonator_id', $admin->id);
    Auth::login($target); // regenerates the session id, KEEPS the data — the flag survives

    /*
     * The panel runs Filament's AuthenticateSession, which flushes any
     * session whose stored password hash no longer matches the authed user.
     * Store the target's hash — exactly the value the middleware itself
     * stores — or the first /admin request while impersonating logs
     * everyone out.
     */
    session()->put('password_hash_web', $target->getAuthPassword());
}
```

- Panel action: `Action::make('impersonate')->label('Sign in as')`
  `->requiresConfirmation()`, visible only when the target is neither an
  admin nor self; calls the Action class, then
  `$livewire->redirect(route('home'), navigate: false)`.
- `app/Actions/LeaveImpersonation.php`: read `impersonator_id`; if the admin
  row is gone or demoted → `Auth::logout()` + `session()->invalidate()`
  (fail closed); else `Auth::login($admin)`, restore `password_hash_web` to
  the admin's hash, forget the flag.
- `app/Http/Controllers/LeaveImpersonationController.php` + route in the
  authed group of `routes/web.php`:
  `Route::post('impersonation/leave', ...)->name('impersonation.leave')` —
  captures the target id before switching back and redirects to that user's
  admin View page.
- **Banner lives in the PRODUCT layout**
  (`resources/views/components/layouts/app.blade.php`, right after body
  open): `@if (session()->has('impersonator_id'))` include
  `resources/views/partials/impersonation-banner.blade.php` — a slim fixed
  amber bar, "Signed in as {name}" + CSRF POST "Return to admin". Product
  tree, so Flux + app.css are available there.
- Auth notes recorded in code: product routes do NOT run
  `AuthenticateSession`, so only panel requests check the hash; the admin's
  remember cookie is untouched, so a mid-impersonation expiry resurrects the
  ADMIN, never strands them as the target. Known edge (accepted): a panel
  session established with "remember me" carries the admin's recaller
  cookie, which can log the session out if the target visits `/admin` — fails
  toward logout, recoverable, and targets are 403 from the panel anyway.

### Relation managers

- **FollowedTeams** — logo, team, `position` pivot with a star badge on 1
  (the favorite). READ-ONLY, no reorder: the order is the user's own curation
  and drives their Home swipe order; a comment says so.
- **Groups** — name, kind badge, flavor label
  (`flavorEnum()?->label()`, placeholder for standard), pivot `role` badge
  (commissioner = warning), pivot created_at as "joined".
- **Picks** — saturday via `slateGame.slate`, matchup via `slateGame.game`,
  picked team, `locked` icon (the Woodshed Lock wager), `result` badge with
  `orderByRaw("field(result, 'win', 'loss', 'push')")`, signed `points`
  colored by sign. Eager `slateGame.slate, slateGame.game, pickedTeam`.
  Comment at the query: deliberately NOT `visibleTo()` — this is an admin
  audit surface and bypasses picks-privacy-until-kickoff by design.
- **WalletEntries** — created desc, reason badge, signed xp/lattes
  (`sprintf('%+d')`, colored), key mono. No edit/delete ever (immutable
  ledger). Header action **Grant** → form (xp, lattes, reason) →
  `GrantWalletEntry::handle($user, $xp, $lattes, $reason)`.

### Tests — `UserAdminTest.php`, `UserImpersonationTest.php`

Search/filters hit real columns; heading + every tab's content; `UserStats`
as a widget class; admin toggle flips via the action AND
`$user->fill(['admin' => true])` stays ignored (mass assignment still
blocked); verify grants XP exactly once (call twice → one wallet row);
delete cascades + self-delete blocked; impersonation full flow (flag set,
session hash equals the target's, banner renders on `/`, the flag survives an
`/admin` hit, leave restores the admin, deleted-admin leave logs out, the
action is hidden for admins/self); every modal action covered via
`TestAction` mounting (modals render under nothing otherwise).

---

## Phase 4 — Pick'em (Group, Contest, Slate)

- **GroupResource (FULL)** — nav Pick'em/1. Table: name (searchable), kind
  badge (private = info, lobby = gray), flavor label, `code` mono copyable,
  `->counts()` members/contests, week number, filled_at since, created desc.
  Filters: kind, flavor, filled ternary. View: heading partial (initials from
  name; badges kind + flavor; meta code / member_cap / created) +
  `GroupStats` KPI widget (members, contests, entries this season, picks this
  season). RMs: **Members** (avatar, name, role pivot badge, joined; Remove
  record action through
  `RemoveGroupMember::handle($actor, $group, $member)` with confirmation —
  surface its guard failures as the action's failure notification),
  **Contests** (season_year, mode badge via `ContestMode::label()`, slates
  count, `->recordUrl()` → Contest view). Edit: `name` and `member_cap` only —
  kind/flavor/week are structural and mode changes belong to
  `ChangeGroupMode` product flows.
- **ContestResource (view-only, `$shouldRegisterNavigation = false`)** —
  reached from Group. Infolist: group link entry, season_year, mode badge,
  `settings` pretty-JSON via the `->state()` collapse rule (json cast renders
  as a list otherwise), mode_changed_at. RM: **Slates** (saturday desc,
  status badge with FIELD() default sort draft→published→prelim→settled,
  games/entries counts, exhibition icon, recordUrl → Slate view).
- **SlateResource (FULL, read-only — `PublishSlate`/product flows own all
  writes; List + View only)** — nav Pick'em/2. Table: saturday desc, group
  via `contest.group` (eager), mode badge, status badge FIELD() sort, counts,
  exhibition icon, bear_theme placeholder. Filters: status, exhibition,
  saturday range, mode (query filter on `contest.mode`). View: heading
  partial (formatted Saturday; badges status/mode/exhibition/bear_theme;
  meta group, week label, deadline via `Cadence::slateDeadline()`,
  published_at) + `SlateStats` KPI (entries, picks made vs possible, games
  lined, settle state). RMs: **Games** (position order, matchup via eager
  `game`, `spread` vs `market_spread` with a warning color when they
  diverge, tier, favorite, `picks_count`, quality), **Entries** (final_points
  desc, tiebreaker_total, won/beat_bear icons). Privacy-bypass comments
  wherever picks are shown.

**Tests — `GroupAdminTest.php`, `SlateAdminTest.php`:** FIELD() order
asserted with one record per status; RemoveGroupMember side effects; Slate
has no create/edit routes; query ceiling on the slates list with eager
`contest.group`.

---

## Phase 5 — Sports reference (Team absorb, Game, Conference, Season)

- **TeamResource rebuilt in place** — `Pages/ManageTeams.php` replaced by
  List/View/Edit. Nav: College Football/2, label "Teams". Keeps the swatch
  columns, the describe helper, and `header_style` as the ONLY editable
  field (same helper text — ESPN sync owns everything else and a hand edit
  dies at the next sync). Adds: conference column for
  `CfbCalendar::currentYear()` via a constrained `seasons` eager load
  (resolve the year once in `->modifyQueryUsing()`), `followers_count`,
  conference + classification filters. View: heading partial (logo; badges
  conference abbrev + classification; meta abbreviation, location,
  renders-as) + `TeamStats` KPI (followers with favorites sublabel, latest
  AP rank, current record from Standing, header style resolved). Infolist
  tab: Identity & branding (colors as swatches, logos, header_style). RMs:
  **Followers** (position pivot, favorite star), **Standings** (year desc,
  source badge), **Rankings** (poll/week/rank, latest first). NO games RM —
  `Team::games()` is a union Builder, not a relation (comment). Update
  `TeamBrandingTest` to the new pages; keep the sync-survival test intact.
- **GameResource (FULL, read-only; List + View)** — nav College Football/1.
  Table: kickoff desc, matchup text composed from the denormalized
  home/away columns (zero joins), status badge (live = danger), week/season
  labels (eager `week.season`), broadcasts via `->state()` collapse (array
  cast). Filters: season year, week (dependent), completed / in-progress /
  upcoming scopes. Global search on `name`. View: matchup heading (two
  logos, big score, status badge) + KPI (attendance, closing line from
  latest odds, predictor win prob). RMs: **Odds**, **ScoringPlays**
  (ordered), **TeamStats**, **Articles**. Resource-top comment: NEVER touch
  `drives`; a test asserts no `game_drives` query ever fires.
- **ConferenceResource (MEDIUM, read-only)** — model edit: add
  unparameterized `Conference::memberships(): HasMany(TeamSeason)` so
  `withCount(['memberships as members_count' => fn ($q) => $q->where('season_year', $year)])`
  works (the parameterized `teamSeasons(int)` cannot feed withCount). Table:
  logo, name, short_name, is_conference badge, members_count. View: infolist
  + **Memberships** RM scoped to the current year (team logo/name/
  classification/division).
- **SeasonResource (MEDIUM, read-only)** — year desc, type → phase label map
  (int constants 1 pre / 2 regular / 3 post / 4 off — a label map in the
  resource, no invented enum), name, date range, weeks/games counts. View +
  **Weeks** RM. Code note: `(year, type)` unique — one CFB year is several
  rows.
- **New factories** (pin ALL randomness — dates, colors, derived columns; the
  TeamFactory alt_color lesson): `StandingFactory`, `RankingFactory`,
  `VenueFactory`, `TeamSeasonFactory`.

**Tests:** `TeamAdminTest.php` (absorption + header_style edit survives, RMs,
KPI widget class), `GameAdminTest.php` (query ceiling with 20 games seeded;
DB::listen asserts no `game_drives` query), `ConferenceSeasonAdminTest.php`.

---

## Phase 6 — Content + engagement (LIGHT tier: ManageRecords + modal ViewAction)

- **AthleteResource** — Content/2. Table-first: display_name searchable,
  position, jersey, current team via the season-scoped pivot chain — eager
  in `getEloquentQuery()` (the 35k-row N+1 trap; ceiling test). Position
  filter. Modal ViewAction infolist with a season-history RepeatableEntry.
- **CoachResource** — Content/3, same shape (careerRecord, current team).
- **VenueResource** — Content/4: name, place, capacity sortable,
  indoor/grass icons, image in the modal view.
- **ArticleResource (MEDIUM)** — Content/1: headline searchable, published
  desc, linked-team badges (eager, capped display), story-fetched icon; View
  page infolist (headline, description, story, teams, source link
  `->openUrlInNewTab()`).
- **WalletEntryResource** (audit) — Community/3: created desc, user (eager),
  reason badge + SelectFilter over distinct reasons, signed xp/lattes
  colored, key mono. NO edit/delete ever (immutable ledger — comment).
  Header **Grant** action → searchable user Select (real name columns) →
  `GrantWalletEntry`.
- **ConversationPostResource** (moderation) — Community/2: created desc,
  user, topic via eager `->with(['topic', 'user'])` (morph map enforced:
  game/team/group aliases) and a `->state()` match over the topic types,
  body wrapped/limited, `topic_type` filter. Record + bulk **Delete**
  strictly through `DeleteConversationPost::handle(auth()->user(), $post)` —
  never `$post->delete()`.
- **New factories:** `AthleteFactory`, `CoachFactory`,
  `AthleteTeamSeasonFactory`, `CoachTeamSeasonFactory` (pinned).

**Tests:** `ContentAdminTest.php` (athlete ceiling, coach/venue render,
article view), `ModerationAdminTest.php` (delete rides the Action — assert
its side effects; bulk works; topic renders per morph alias),
`WalletAdminTest.php` (grant through the Action; no edit/delete actions
render).

---

## Phase 7 — Polish

- Global search attributes on Team (location/name/abbreviation), Group
  (name/code), Article (headline); Athlete/Coach only if not sluggish at 35k
  rows (`$globalSearchResultsLimit = 10`, or opt out).
- Navigation badges only where they carry signal (Workbook keeps its open
  count) — badge noise is the enemy of a compact rail.
- Sweep: consistent empty states, `->placeholder('—')` wherever data can be
  null, American spelling, factual copy (admin is not a Voice surface).
- Revisit `->spa()` with `spaUrlExceptions()` only if panel snappiness ever
  matters more than the cross-bundle risk.

---

## Verification (every phase lands green before the next)

1. `php artisan test --compact --filter=<phase tests>`, full suite before
   merge. Modal coverage via `TestAction` mounting; widgets tested as
   classes, never through page HTML.
2. `vendor/bin/pint --dirty --format agent` after any PHP.
3. `npm run build` after any Blade/theme change — the panel 500s without a
   built manifest.
4. Browser at real widths: `/__device?path=/admin&w=390,768&h=800` — topbar
   chip visible at 390, sidebar rail usable when collapsed; impersonation
   banner checked on `/` at 390.
5. Impersonation walked end-to-end manually once: impersonate → app banner →
   return to admin.

## Risks / open items

1. `LiveState` visibility promotion — confirm no test pins method privacy
   (payload shape unchanged).
2. The impersonation hash-swap is pinned by the "flag survives an `/admin`
   request" test so any framework change fails loudly; the remember-me edge
   fails toward logout (acceptable).
3. `group_members` FK cascade re-verified in migrations before shipping
   `DeleteUser`.
4. Contest mode/settings intentionally NOT admin-editable (product flows own
   mode changes) — flag to the owner if wanted later.
5. Athlete global search is `LIKE '%…%'` over 35k rows — measure, opt out if
   it drags.
