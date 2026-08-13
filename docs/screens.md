# Screens: what each one does and why

Home, Search, Scores, Game, Team, Players and Account — the behavior of each
screen and the constraints it is built around. Shared chrome and layout rules
live in [ui-system.md](ui-system.md).

## Home is the user's teams, swiped

One at-a-glance card per followed team — record, standing, trend pills, live
or next game, last result — in the order the user set on Account, the same
order the scoreboard floats them in. Native `scroll-snap` IS the animation: no JS tween, no
library; momentum scrolling is what feels buttery. An IntersectionObserver
sets the active index; the dots and the per-team news lists key off the same
`glances` array index, so they cannot disagree about which team is showing.

**Every followed team's news renders up front and Alpine toggles it** — at
most 5 teams × 5 articles. A Livewire round trip per swipe puts a visible
stall on the one interaction that has to feel instant.

**One query per CONCERN across all teams, never per card.** Completed games
(form + last result), pending games (live + next), and the news join are each
one query for all five teams; everything else comes from TeamGlance's cached
maps. `HomeTest` asserts the page issues the SAME number of queries for one
followed team as for five — that is the regression that matters once cards
multiply.

Two scoping rules that look wrong until August: form is scoped to the results
year (or it walks back through a decade of games), but pending games are NOT
season-scoped — in August the results year is fully complete and the next
game belongs to the season that has not started counting yet. The card polls
(`wire:poll.30s.visible`) only while one of the teams is actually live, and
reads only our own database.

The Pick'em teaser card WAS deliberately inert; the Picks screen exists now
(`/picks`, the fifth nav area, a designed coming-soon page), so the whole
card navigates there — the entry point it always planned to become. The
wallet chips (`x-wallet-chips`: Beast Latte balance and XP are REAL sums
from the wallet ledger now — verification pays 100 XP + 1 latte, the
onboarding moment seeds 25 XP — while the rank stays the literal "Rookie"
until Phase 7 defines the ladder) ride `x-home-nav`'s reserved slot
below `sm` and the layout header above, both wearing `data-tour="wallet"`.

**The last card is an empty SLOT until five teams are followed, and the
first is Bandwagon State until ONE is.** Onboarding happens in place: a
signed-in user with no teams gets a swiper holding the placeholder card
(`x-team-placeholder-card`, dashed, absurd on purpose — see
`App\Support\PlaceholderTeam`) and one add-card. The placeholder is a button
that opens the picker, keeps the news track index-aligned with its own joke
panel, and is what lets the guided tour run for someone who skipped the
picker. **There is no separate favorite mechanism** — follows are an ordered
list, position 1 IS the favorite, and the picker now says so out loud.

**A horizontal swipe works ANYWHERE in the team section** — dots, news
panels, subheadings — not only on the card track. Touch handlers on the
section converge on the dots' own `scrollIntoView` idiom (guarded so touches
beginning on the card track stay native, and gated on clear horizontal
intent so a page scroll never advances the swiper). The news track stays
`overflow-hidden`: it is a follower, and two scrollers can disagree about
which team is showing.

That makes the swiper's card list DYNAMIC, which broke the original
IntersectionObserver: it captured `[...track.children]` once in `x-init`, so
a card added mid-session was never observed and the dots stopped tracking the
swipe. The observer now re-runs `observe()` on every `childList` mutation and
resolves the index from a live `children` lookup — `IntersectionObserver`
ignores a repeat `observe()`, so it stays idempotent. Anything that inserts
into an observed list needs the same treatment.

`TeamGlance::fbsTeams()` is the one FBS picker list, shared by Account's
follow search and Home's quick add so the two cannot drift.

## Home's nav scrolls; the search bar is what pins

The brand bar above Home's search (`x-home-nav`) is phone-only and deliberately
NOT sticky. Pinning both would put ~44px of permanent chrome back at the top of
a 390px screen, in an app that cut its chrome from 197px to 73px on purpose — so
the brand greets you on arrival and gets out of the way, and the scrolled state
is byte-identical to what it was before the nav existed. Measured: nav at
`top: 0` height 54, search at 54; after scrolling 400px the nav is at -400 and
the search bar sits at exactly 0.

Its right-hand cluster is an empty slot reserved for gamification — currency, XP,
streak. At `sm` the nav retires and those chips belong in the layout header
instead, which is the same additive rule everything else follows.

**The knock-on is one negative margin.** The search bar's wrapper was `-mt-5` to
cancel the layout container's `py-5` while it was Home's FIRST child. It is not
anymore, so it is `-mt-6`, cancelling Home's `gap-6`. Get it wrong in either
direction and the bar rests below where it sticks, which shows up as the heading
drifting on the first flick of a scroll rather than as a spacing bug.

**Account lost its visible heading to the brand.** The tab that got you there
already says "Account", so the word survives as `sr-only` — the same call every
League screen makes. Scores keeps its heading and gains the mark beside it,
because it is still the app's one non-redundant screen title.

## Search: three surfaces, one backend, and deliberately no FULLTEXT

Search is the bar at the top of Home (expands full-screen IN PLACE — never
navigate, because programmatic focus cannot raise the mobile keyboard; only
the input the user tapped keeps it up), the `/search` deep-link page, and the
desktop ⌘K palette.

That bar STICKS at `top-0` — below `sm` there is no header, so the top of the
screen is the top of the viewport and the offset needs no measuring. It cancels
the container's padding and re-applies it inside (`-mx-4 px-4`, `-mt-5 pt-5`)
so it has nothing to travel through, and `pb-3 -mb-3` gives content somewhere
to disappear without disturbing Home's `gap-6`. That last pair also nets the
wrapper to zero flow height while the panel is open, so the fixed overlay
leaves no residual gap behind it.

**It wears the layout header's own surface**, verbatim: `bg-white/85` with
`backdrop-blur` and a zinc `border-b`. Below `sm` that header is hidden, so on
a phone this bar IS the header — matching it makes them one object at two
widths instead of two pieces of chrome with different ideas, and gives Home a
formal top edge. Verified at both widths: at 390 the bar is 73px and the header
is 0px with no rule; at 768 exactly the reverse, with identical computed
background, blur and border color. Neutral throughout — the separation comes
from the rule and the blur, never from a tint or a brand color.

Translucency is safe HERE and was not on the scoreboard's day headings, which
is worth keeping straight: this sits at z-30, above card contents at z-10, so
it wins on z-index and the blur is decoration. A day heading tied at z-10 and
lost on tree order, and no amount of opacity fixed that.

**But the panel is a `fixed` child of that bar, and every class that makes the
bar a header breaks it.** All three come off while it is open
(`:class="{ 'sticky z-30 backdrop-blur': ! open }"`), and each one fails in its
own way:

    backdrop-blur   a backdrop-filter is the CONTAINING BLOCK for fixed
                    descendants, exactly like transform and filter. `inset-0`
                    resolved against the 33px bar, so full-screen search opened
                    as a 390x32 strip with Home still live underneath
    z-30            a stacking context CAPS the panel's z-50 at 30, under the
                    tab bar at z-40
    sticky          opens a stacking context at `z-index: auto` as well, which
                    `relative` does not — so dropping to z-auto fixed nothing

That last one is the surprise, and it REFINES the note above about
`position: relative` with `z-index: auto` creating no stacking context: sticky
is not the same, it always creates one. Verified with an isolated pair of fixed
divs rather than reasoned about — a z-50 child of a `sticky; z-index: auto`
wrapper loses to a plain z-40 sibling.

Object syntax rather than a ternary, because those classes are also in the
static `class` attribute: Alpine's `setClassesFromObject` removes a class
whatever put it there, so the server still renders a dressed bar and there is
no flash before Alpine boots. Only those three toggle — moving the padding too
would shift the page 32px on every open. All three read `App\Support\Search`, which is Laravel
Scout on the DATABASE engine — the data is already in our MySQL, so search
queries source tables and there is no index to sync or drift.

**No MySQL FULLTEXT, and that is a decision, not an omission.** An InnoDB
full-text index cannot see rows inserted inside an uncommitted transaction —
which is every row a RefreshDatabase test creates — so a full-text arm passes
in production while being unprovable in the suite. LIKE strategies test
honestly. At our sizes only `athletes` (34,836 rows) needs indexes at all:
its strategy is `SearchUsingPrefix` on `display_name` + `last_name`, riding
btrees. Everything else contains-matches, which is not a compromise — it is
required: `games.name` is "Alabama at Georgia" so the away team is
unreachable by prefix, and `games.note` is the real bowl name where the word
someone types is rarely the first.

**Relevance is domain knowledge, not text statistics**, and lives in each
group's `->query()` callback: live > upcoming > finished and nearest-to-now
for games; active players above departed ones, then latest season; FBS teams
first; `is_conference` first (only 79 of 118 rows are real conferences);
current coaches above historical. Ranked teams float by a PHP re-sort of the
fetched page against `TeamGlance::ranks()` — every ranked team is FBS, so the
SQL order has already pulled them into the page.

**Result rows are rich but factual** — search serves Scores and League, so
only the empty state speaks through `Voice`. Rank is a small muted numeral
BESIDE the team name, never a subtext segment. Hometown gets its own micro
line, never another `·` segment — it is the first thing truncation eats, and
only about half of athletes and coaches have one, so every row must read
right without it.

`App\Support\TeamGlance` holds the cached glance maps (records, standings
position, conference names, ranks, conference sizes) as PLAIN ARRAYS — one
query per map over the whole league, never per row. It memoizes in a static
property on top of the cache, which outlives each test's application;
`tests/Pest.php` flushes it in `beforeEach`.

## Float followed teams by PARTITIONING, never by a second query

A signed-in viewer's teams are lifted to the top of the scoreboard out of the
games the scope already admitted — one pass over one result set, not a separate
fetch per team.

That is what makes the rule hold without anybody writing the rule: pick Top 25
while your team is unranked and their game was never in the set to be lifted out
of, so it does not appear. Fetching it separately and re-checking the scope
afterwards is the same behavior held together by a condition that has to be kept
in step with `Scope` forever.

**All followed teams float, in the user's own order.** Four things the
presentation has to get right:

- **First team to want a game claims it.** Two followed teams playing each other
  is one game, shown once under whichever of them ranks higher — walking the
  teams in priority order and marking each game claimed is what prevents the
  same card appearing under both.
- **Move it, do not copy it.** A pinned game is removed from its day group. A
  card appearing twice reads as a duplicate fixture, not as a ranking.
- **Carry the date on the pinned heading.** Lifted out of the chronology a card
  only says "7:30pm", so the heading reads `Tennessee · Saturday, Sep 12`.
- **No union, and none needed.** This once had to merge a separate favorite
  into the followed set, because a favorite lived outside the list and could
  disagree with it. An ordered list cannot, which is the point of the change.

The empty state must check BOTH halves. A week whose only in-scope games belong
to the viewer's teams leaves the day groups empty, and keying the callout on
those alone prints "Nothing on the slate" directly above their game.

**Follows are capped at `User::MAX_FOLLOWED_TEAMS` (5).** Past a handful the
pinned block stops being a shortcut and becomes the slate again, and each
follow also commits us to syncing that team's news.

`FollowTeam` throws `FollowLimitReached` rather than silently declining, because
a write that quietly does nothing gives you a button that looks like it worked
and a news tab that never fills in. It checks "already following?" BEFORE the
cap, or a user sitting at exactly five could not press Follow on a team they
already follow.

## A filter that cannot mean anything must be disabled, not silently remapped

`Scope::teamIds('top25')` falls back to FBS when a season has no poll — which is
the normal state all summer, since the preseason AP does not land until August.
On its own that made the scoreboard read "Top 25" while showing all 138 FBS
teams.

So `Scope::hasRankings()` drives two things: the option renders disabled with
"No poll yet" beside it, and `Scope::defaultFor()` opens the screen on FBS. The
fallback stays as a backstop for a URL carrying `scope=top25`, because an empty
Top 25 showing "Nothing on the slate" as a visitor's first screen is worse.

Disabled options are rendered as plain divs, NOT `flux:menu.item` — menu items
are focusable and selectable, so a disabled one still lands under the keyboard.

## The Game screen is one shell in three states, and the first tab IS the state

    pre    Preview  — odds, matchup predictor donut, comparison bars, last
                      five, season leaders, last meetings, game information
    live   Live     — situation on the scorebug, win probability, drive feed
    final  Recap    — line score, recap article, game leaders, probability
                      swing, related reading

Box · Scoring · Drives · Odds ride behind whichever leads, each offered only
when its data exists. `$tab` is `#[Url]`; mount() AND poll() normalize it, so
`?tab=live` bookmarked mid-game resolves to Recap after the whistle instead of
an empty pane.

**A game that has not kicked off has exactly ONE tab, so it draws no strip at
all** — the pregame screen is a single scroll in the order above. Odds LEAD it:
the line is the one number a reader checks before kickoff whether or not they
bet, and a two-item strip whose second item is one table charges a tap for
something the page can just show. `partials/game-odds` takes `standalone`,
false when folded in, which drops its quality table (the donut two cards below
already prints matchup quality) and its empty state (the preview owns the
apology, and that apology must check for ODDS too — printing "nothing yet"
above a posted spread is the same mistake as an empty state above a followed
team's game). Odds keep their own tab from kickoff on, where the scroll belongs
to the box score. The game-information card is the parent's, rendered once at
the foot of every state — the preview must never grow its own.

Rules the screen keeps, each one paid for:

- **Drives are read ONLY while a tab showing them is active** — a computed
  gated on `$tab`. game_drives is ~306 KB a row in its own table precisely so
  a page view does not read it; `GamePageTest` asserts the recap tab issues
  zero `game_drives` reads. `hasDrives` (an exists() on the PK) is what offers
  the tab.
- **The scorebug links both teams.** Cards send every tap to the game because
  the team links live HERE — `LinkingTest` enforces it, and it is the first
  thing a redesign quietly drops.
- **The sheet is a sibling of the scorebug, never a child** — the scorebug's
  backdrop-blur is a containing block for fixed descendants (the search-panel
  lesson), and would cap the z-50 sheet at the scorebug's own size.
- **Around the League claims each game once**: followed → Top 25 (via
  GameRanks) → this game's conference(s) via season-scoped team_seasons →
  rest of the ET day, computed only while the sheet is open. Drag-to-dismiss
  and the entrance spring run through `element.animate()` (nothing inline for
  a morph to strand); `x-trap.noscroll` does focus trap and scroll lock in
  one; multi-statement Alpine lives in x-data METHODS.
- **`SyncPredictors` is upcoming-only and that is a one-way door**: ESPN
  serves predictors for unplayed games only, so a projection not captured
  before kickoff never exists. Wed/Thu/Sat-morning passes are the capture;
  `CoverageReport` watches the coming 10 days. The row also keeps
  `pred_pt_diff` (projected margin) and opponent-strength ranks;
  `teamChanceLoss` is the projection's complement and is derived, never
  stored.
- **The live situation clears when a game LEAVES the in state, and only
  then.** A final must not wear a frozen "3rd & 7", but a live payload
  omitting the block is a transient gap — nulling real data over it is the
  default-writing mistake. Possession ids obey the non-positive rule.

### The donut: both arcs leave top dead centre, and nothing animates

Home sweeps clockwise down the right, away is the same arc MIRRORED
(`translate(120,0) scale(-1,1)`) so it leaves the same point going the other
way and runs down the left. Each team's color therefore sits under its own
logo, and the split is at twelve o'clock whatever the numbers say. Two
earlier shapes were wrong: drawing away first put its color on the RIGHT
under the home logo, and starting the second arc where the first ended fixed
the colors but let the origin wander with the split.

Round caps EXTEND a dash by half the stroke width at each end, so the offset
that yields a visible gap of G between neighbouring ends is `G/2 + stroke/2`,
applied at both the twelve o'clock split and the bottom meeting point. A
plain `- $gap` produces no gap at all.

**It is drawn STATIC, and that is the load-bearing part.** Two entrance
animations were tried and both could render an EMPTY ring: an Alpine flag
flipped from `requestAnimationFrame`, and a CSS keyframe animating from a
zero dasharray. Measured in a real browser, `getAnimations()[0]` reported
`playState: "running"` with `currentTime` frozen at 0 across seconds — so the
arcs held their from-state indefinitely and the card showed nothing. This is
the same no-frames condition documented for the automated tab, and the rule
it teaches is general: **a flourish whose stalled state hides the content is
not decoration.** Animate only where the un-animated state is the finished
one.

### Chart marks: team colors in light, neutral in dark, resolved as a PAIR

`TeamPalette::chartColors(away, home)` — the donut and comparison bars draw
in team colors in light mode, and BOTH pairwise failure modes are real: a
near-white brand vanishes into the page, and two red teams merge into one
ring. The away side keeps its primary; the home side yields — its own
secondary first (Alabama gray beside Georgia red is truer than a shifted
red), then a lightness shift, then a neutral that always reads. Dark mode
un-brands both through the `chart-pair` utility (zinc vs accent), the same
rule as every branded surface — and color is never the only distinguisher,
because every mark carries its team's abbreviation. Floors: 2.0 against the
page, 1.25 between the marks; the tests assert RATIOS, not which hex was
picked.

## The team page: four tabs, schedule first

    Schedule · Roster · Stats · News

Schedule leads because it is what someone opening a team page came for. There
is no Overview — its only content was the leaders, which belong under Stats.
The season select sits inline to the RIGHT of the tab strip, unlabeled (four
digit years are self-evident, and the label was the widest thing on the row);
its accessible name is an `aria-label`. The strip scrolls inside its own
`min-w-0` track so it shrinks rather than pushing the select off-screen.

Stats answers two different questions and so carries its own toggle:

    Players   who on this team is good — headline lines with a full stat
              line, then per-position groups (Passing, Rushing, Receiving,
              Defense)
    Team      how good the team is — categories bucketed into Offense,
              Defense, Special Teams

**Two levels of navigation get two visual languages**, and on this screen the
two languages SWAPPED. The section tabs are `x-team-nav` — underlined, ruled,
edge to edge; the scope toggle inside Stats is a segmented pill gutter. It was
the other way round, and the swap came with the nav: once the top row owns the
underline-on-a-rule idiom, leaving the Stats toggle as a bleeding `x-plate` put
two ruled underlined rows on one screen, a child that looks exactly like its
parent. Which is the same confusion the rule has always been about, arriving
from the other direction.

So on a team page: **NAVIGATION underlines, a FILTER INSIDE a section is
pills.** The roster's squad filter was already a gutter, so the two sub-filters
now agree with each other. `/stats` keeps its plate — no hero, no nav above it
to collide with.

Both bucket maps keep an "Other" catch-all, because ESPN adds categories
without telling anyone and a hardcoded list silently drops them. Reading
ESPN's own order put `defensive` first and `scoring` near the end, so the
screen opened on tackles rather than points.

**TEAM leads, PLAYERS follows** — here and on League's Stats screen, so the
same control does not read two ways in one app. The leftmost tab is the
default, as everywhere else.

## Position data exists for the CURRENT roster only

Measured across `athlete_team_seasons`, and it is what shapes `/players`:

    2026   13,580 rows   13,580 with a position   ALL FBS, no FCS roster
    2025   12,571 rows      398 with a position
    2024   12,307 rows      700 with a position

ESPN publishes only the current roster, so every earlier row is derived from a
box score: it carries a jersey and a team and no position. Two rules follow:

- **There is NO season picker on `/players`**, and that is the screen's shape
  rather than an omission: an earlier season is a name list with the position
  filter switched off. The year is the newest season that HAS a roster — not
  `resultsYear()`, which points at the last season with GAMES and is a year
  behind all summer. A player's history lives on their own page.
- **The position filter gates on COVERAGE, not presence.** Those 398 rows span
  most abbreviations, so a strip built from "distinct positions this season"
  looks complete and filters to 3% of the roster. It renders only where
  positioned rows are at least half the season — which, with no season picker,
  now only fires if the newest roster is itself box-score-derived.

And `athlete_season_stats` tops out at 2025, so that year has no stats at all —
`/players` shows roster facts and nothing else. That is honest for a season
that has not kicked off, and it is why it is not a stats screen.

**The position filter is a MENU, ordered by SQUAD, not alphabetically.** It
was a scrolling pill strip — 1,015px of pills in a 390px track — until the
no-horizontal-scroll rule (see "League chrome speaks one vocabulary") moved
any open-ended set into an `x-filter-menu`, which also gave the screen back a
whole chrome row. Order still matters in a menu: alphabetical buried QB
seventeenth behind C, CB, DB, DE, DL, DT, EDGE... It sorts by ESPN's own
`position_group` — offense, defense, special teams, the order every roster
page uses including our own team page — then by squad size within each, which
puts QB fifth. Derived rather than a hardcoded list, so a position ESPN adds
lands in its group instead of being dropped. Note the cache:
`players:positions:{year}` holds the ORDER too, so changing the sort needs
`cache:clear` to be visible. The menu's trigger shows only the current
selection, which is why the search placeholder names the position — "Search
Quarterbacks…" — instead of a heading repeating the filter.

**Position ABBREVIATIONS collide across ids.** Among positions with 2026 rows,
`LS` resolves to two (39 with 256 players, 78 with 13). A select keyed on
`position_id` renders "LS" twice and each entry silently hides the other's
players. Key the filter on the abbreviation and match every id that shares it.

`/players` is driven from `team_id IN (...)` via `Scope::teamIds()` because
there is NO index leading with `season_year` — the usable one is
`(team_id, season_year, position_group)`. Measured: 64ms unfiltered and sorted,
2ms once a name prefix rides `athletes_last_name_index`.

Its name filter is a PREFIX, matching `Search::players()` and the model's own
`#[SearchUsingPrefix]`: "Smith" finds every Smith through `last_name`, "mith"
finds nobody. A screen matching differently from the search bar above it would
read as a bug.

Sorting is **Name · Last (A–Z) · Last (Z–A) · Team**, defaulting to Last
ascending — how a roster, a box score and a depth chart are all listed, and it
agrees with what the name filter matches. Name means first-then-last; sorting a
roster by given name alone answers nothing.

**Direction rides IN the sort value, not in a second property.** Only surname
sorting has two useful directions — "teams, Z first" is not a question — so a
separate `$direction` would be a control meaning nothing for three of four
options, the same trap as a Top 25 filter with no poll behind it. One value also
keeps every option directly clickable rather than hiding the reverse behind a
second click on the option already selected.

Ties break on whatever half of the name is left, **in the same direction**: a
list reversed at the top and ascending underneath is not reversed, it is two
sorts. Verified on the 44 Williamses — ascending gives Aaron, Anthony, Arion;
descending gives Zach, Xavier, Tyson.

That is a reading choice, not a cost one: measured at 118/91/116ms, the options
are the same price, because the query is driven from `team_seasons` into
`athlete_team_seasons` and so ordering on any `athletes` column is a filesort.
`athletes_last_name_index` serves the name FILTER, never the sort.

`$sort` is normalized in BOTH `mount()` and `updatedSort()` — `#[Url]` hydrates
from the querystring without firing the update hook, so a bookmarked
`?sort=nonsense` would otherwise reach the query builder as a column name.

## Infinite scroll grows a LIMIT, and the payload is the price

`/players` loads 50 rows and grows by 50, driven by `wire:intersect` on a
sentinel that is also a real `<button wire:click>` — the observer never runs for
a visitor with JS off or a throttled background tab, and 50 of 13,580 with no
way forward is not a list.

It cannot run away: a chunk is 50 rows at 64px, so loading one pushes the
sentinel ~3,200px down, past any viewport plus the 600px margin, and
`wire:intersect` only fires on ENTERING. `loadMore()` guards on the total anyway.

**The query cost is flat** — measured 259/164/154/187ms at limits of
50/200/500/1000. The filesort over 13,580 dominates, so fetching more rows is
free. That is the opposite of the intuition and it is why a growing LIMIT is
tenable at all.

**The response is not.** Livewire re-sends the whole rendered component, so each
load carries every row already on screen: measured **1,244 KB** for the load
that took 500 rows to 550, then 1,354 KB, then 1,463 KB — about 110 KB more each
time. Realistic depth (2-5 chunks behind a position filter) is 250-600 KB, which
is heavy but works; deliberate deep scrolling is not.

The cheaper shape is `@island(name:…)` plus `wire:island.append`, which sends
only the new chunk — a constant ~110 KB. It was NOT taken, and the reason is
worth keeping: an island is skipped on a parent re-render and replaced wholesale
when forced to run with `always: true`, so its body must render only the current
chunk (`forPage`). Any parent re-render that does not first reset the page then
collapses the list to whatever that chunk happens to be. Today every filter path
does reset, so it would work — and it would keep working only for as long as
nobody adds a control that re-renders without resetting. Growing a LIMIT is the
slower option and the one that cannot show the wrong rows.

**Verifying it needs the button, not the scroll.** The automated tab delivers no
IntersectionObserver entries, so `wire:intersect` cannot fire there — drive the
end state instead: click the sentinel, or call `loadMore()` and assert the row
count. The scroll trigger itself only fires on a real device.

`x-search.player-row` takes an optional `season`, defaulting to `latestSeason`.
Search wants the default — it has no year in mind. A YEAR-SCOPED caller must
pass the row it is showing or a 2024 list prints everyone's 2026 team.

## A game card goes to the GAME

The whole card is one link to the game page; team names inside it are plain
text (`x-team-link :link="false"`), and every row above the overlay anchor is
`pointer-events-none` so taps fall through. A reader tapping a game card wants
the game summary far more often than an opponent's team page — teams are one
more tap away on the Game screen, which is where the team links live.

**A control ON the accent must draw its colors FROM it.** The follow button
lives in the hero and is hand-rolled rather than a `flux:button`: no fixed
variant holds contrast across 136 team colors. Follow INVERTS the hero —
`background: var(--team-accent-contrast); color: var(--team-accent)` — so it
reuses the one pairing the header already proved readable, and Following
recedes to an outline in `currentColor`. Same rule for the limit message
beside it: `opacity-90` on the inherited color, never a fixed amber.

## A game card names the PLACE, not the team

`Team::placeName()` — "North Carolina", never "North Carolina Tar Heels". A card
is scanned rather than read, and the nickname is decoration sitting in front of
the word the reader is looking for.

Past 16 characters it falls back to `short_display_name`, ESPN's own shortening
(FIU, Jax State, Mississippi St, N Illinois). That is 4 of 136 FBS teams; for
103 the two columns are already identical, so the substitution is invisible
wherever it is not needed.

**It is not breakpoint-gated, and must not be.** A card is roughly 334px inside
at 390px single-column but only ~276px when it goes two-up at `sm` — so the
phone is the WIDEST case, and a `sm:` swap would put the short name where there
is most room and the long one where there is least. The threshold is sized to
the two-up card's ~144px name column.

`location` must be in every constrained eager load feeding a game card
(scoreboard, home, team, conference, game) — the usual missing-column trap.

## The pin mark, and where it still lives

Bootstrap's `pin-angle-fill` in blue marks the team a user ranked FIRST on the
scoreboard's floated block — not every followed team, which the reader can
already see from position. Heroicons has no plain pin, only `map-pin`, which
reads as a LOCATION and is actively wrong in an app full of venues.

The pin no longer means "favorite" — that concept is gone (see the ordered
list above) — and Account uses drag handles rather than pins to say the same
thing.

## One card for teams, and never two searchable listboxes on a screen

Account has a single "Your teams" card: a search that follows, and a list with
a drag handle plus up/down buttons on each row.

It was two cards, and the split caused a real bug. Choosing the lead team was
its own `flux:select variant="listbox" searchable` over EVERY FBS team, sitting
on the same screen as the follow search. **Picking a team to follow silently
rewrote the other selection** — the two listboxes shared option values and
cross-wired, so an add wrote to both bound properties. It looked like teams
were vanishing from the follow list, and the tell was the survivor pattern.

Reordering the list the user already has removes the whole class of problem:
one searchable listbox on the screen, so nothing to collide with, and ranking
cannot pull in a new team so it can never hit the follow cap.
`ReorderFollowedTeams` still validates membership, because it is reachable
from a public Livewire method and the client can send any ids.

Both pickers — this one and Home's quick add — read `TeamGlance::fbsTeams()`,
so they cannot drift or pay for the query twice.

## The verified landing is a tab ending well, on purpose

`/verified` (`auth.verified`, route `verification.done`) is where a verify
click lands for a reader the server knows runs the installed app
(`User::hasInstalled()`). Its one job is to END the browser tab: celebrate
the payout, then point at the home-screen icon — which is why it wears the
AUTH layout. Full app chrome would invite staying in the browser and
training a browser-tab twin of the app; the auth layout is the established
interstitial frame and already carries the depth-aware Back.

Two bodies, split by STYLESHEET and never JS (neither may flash before
Alpine): the browser variant wears `data-install-only` and coaches back to
the icon with a quiet "Continue in browser" underneath; the in-app variant
wears `data-standalone-only` for the Android link-capture case, where the
click lands inside the PWA and coaching would be nonsense. `mount()` bounces
unverified visitors to the notice screen — the page must never claim a
verification that has not happened. `VerifiedLandingTest` pins the branch,
both bodies, and the ignored `intended()`.
