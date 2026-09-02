# Screens: what each one does and why

Home, Search, Scores, Game, Team, Players, the Lobby and Account — the
behavior of each screen and the constraints it is built around. Shared chrome
and layout rules live in [ui-system.md](ui-system.md); the pick'em rules the
Lobby fronts live in [game-modes.md](game-modes.md).

## Home is the user's teams, swiped

One at-a-glance card per followed team — record, standing, trend pills, live
or next game, last result — in the order the user set on Account, the same
order the scoreboard floats them in. Native `scroll-snap` IS the animation: no JS tween, no
library; momentum scrolling is what feels buttery. An IntersectionObserver
sets the active index; the dots and the per-team news lists key off the same
`glances` array index, so they cannot disagree about which team is showing.

**Every followed team's news renders up front and Alpine toggles it** — at
most 5 teams × 3 articles (trimmed from five on 2026-08-31; each panel now
ends in a "More {Place} news" door to the team page instead of two more
headlines). A Livewire round trip per swipe puts a visible stall on the one
interaction that has to feel instant.

**The 2026-08-31 momentum pass** (docs/plans/home-and-picks-pass.md) gave
Home the reader's own pick'em state: a member with slates on the card gets
a "Your picks" section of compact `x-slate-row`s in the teaser's slot
(guests and card-less members keep the teaser, and the tour's room anchor
rides whichever renders), a single `x-next-up` nudge card above the fold
(PickemPulse's priority ladder — one card, never a stack; it yields to the
onboarding CTA and stays silent for unverified readers), Latest news
trimmed to three behind its unconditional "More", and a foot door — the
Lobby's lean room count while the flag is open, the League otherwise — so
the page never ends on somebody else's articles.

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

The Pick'em teaser card WAS deliberately inert; the Picks area is REAL now
(`/picks`, the fifth nav area — see "My Picks" below), so the whole card
navigates there. Outside the `pickem` flag the teaser still wears "Coming
soon", matching the promise both pick'em doors keep for outsiders. The
wallet chips (`x-wallet-chips`: Tallboy balance and XP are REAL sums
from the wallet ledger — verification pays 100 XP + 1 Tallboy, the onboarding
moment seeds 25 XP — and so is the RANK, computed from that XP total by
`App\Support\RankLadder`: Walk-On · Redshirt · Rotation · Starter · Captain
· All-American · Legend, a pure function of one integer with no stored
column to drift. The chip has room for the rung and nothing else, so My
Picks carries the only surface that names the NEXT one) ride `x-home-nav`'s
reserved slot below `sm` and the layout header above, both wearing
`data-tour="wallet"`.

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

## My Picks is the reader's own pick'em week

Where the Picks tab lands (`/picks`, `pickem-home`; sections **My Picks |
Lobby | Leaderboard | History** inside the `pickem` flag, the coming-soon
promise verbatim outside it). Pass 4 (2026-08-20) SPLIT pass 3's single
screen in two. Pass 3 had rejected a mine/discovery split on the grounds
that our content volume was "a handful of cards, not a thousand public
groups" — the flavored-rooms build then shipped thirteen public rooms, and
the reasoning expired with the premise. One screen was carrying seven
zones, ~2,300px of scroll, six container treatments and three card species
at three heights, with the Saturday it was selling 1,400px above the rooms.

This half keeps everything that is ABOUT THE READER, one column ordered by
urgency:

1. **The group switcher** (`x-group-switcher`, 2026-09-01) — centered above
   the This week | Results fork, the one piece of chrome allowed above a
   plate (`docs/ui-system.md` rule 8). It answers "which of my seats am I
   looking at" before the plate asks "which half": "All my picks", then
   **My Groups** (private, by name), then **Week N Contests** (the public
   rooms the reader is SEATED in this Saturday, the always-open tables, and
   "Browse the Lobby · N open" — the Lobby is where a room is joined, so
   the menu lists what you hold, never what is for sale). The current row
   is bold. Pure navigation off `App\Support\Seats`, the one read this
   screen and the clubhouse share (held in a `#[Computed] seats()`, handed
   to the switcher as a prop): no Livewire state, no query of its own,
   renders once (`data-group-switcher`), and only for a reader with a seat
   — the first run is byte-identical. The same control is the clubhouse's
   title, so a reader inside one seat reaches any other in one tap. It came
   from the first onboarded readers, who held several groups and rooms at
   once and could not tell one card from the next.
2. **The week ribbon** (`x-week-ribbon`, the group-hero band grammar) — the
   dateline from `CfbCalendar::defaultWeekEntry()` plus ONE clock line by
   urgency: games live now → the next kickoff → a commissioner's slate
   deadline. No calendar entry, no ribbon — never a substituted week.
3. **Needs your picks** — renders only when a published slate is still
   taking picks the reader hasn't finished: ONE mode-tinted hero card (the
   slate closest to locking: live first, then soonest kickoff, a missing
   kickoff last) wearing the zone's only button, and under it one plain
   "and N more below" line. This zone is why the screen works: it answers
   "what do I do right now" before anything else talks. The compact rows
   that used to follow the hero retired 2026-09-01 — every card that needed
   picks rendered twice, as a row here and as a card below, and a reader in
   four groups met eight cards before the fold. `x-slate-row` now renders
   only on Home's picks strip.
4. **My Groups** — the season-long, private half under its own heading,
   `x-group-card`s with the mode mark, palette and pass 2's five-way state
   row (waiting / upcoming / live / prelim / final) intact, the "Start a
   group" escape on the heading row for a reader who already has groups,
   and the invite-code disclosure ("Have an invite code?", auto-opens on a
   code error) directly under the cards — ONE unconditional render site,
   because a bad code has to open a form for a reader with no seats at all.

   Three corrections, in order. 2026-08-29 SPLIT one "Your groups" heading
   into three, because a public room joined an hour ago sat under the
   season-long word beside a league and nothing said either was what it
   was. 2026-08-31 merged the headings back and moved the distinction onto
   the cards ("three headings over one thumb of cards read as three
   products"). 2026-09-01 split them again — into TWO, and the same two the
   switcher's menu shows: a stack of cards each carrying its own kind line
   read as one product with fine print, and a reader in several groups and
   rooms could not tell the cards apart. Two headings that are the menu's
   own sections are the taxonomy said in two places, not three products.

   So the kind is on the HEADING, and the card's micro-line is facts: "12
   members · you're the commissioner". An evergreen alone keeps "Always
   open ·" in front, because its section names a Saturday and a table is
   not one — and never "table": the house has exactly two user-facing
   container nouns. One Voice definition under the heading,
   `picks.groups.subheading`.
5. **Week N Contests** — the public half: this Saturday's rooms the reader
   is seated in (`roomCards`, past Saturdays excluded), then the always-open
   tables (`tableCards`), then the ONE Lobby door (`partials/lobby-door`: a
   PLAIN COUNT, "3 public rooms open this Saturday", or `lobby.publics.empty`
   at zero, over one optional Voice line, navigating to `/lobby`, reading
   `Lobby::openRoomCount()` through Seats — never the inventory).
   `LobbyRoomsTest` pins that count equal to what the Lobby actually lists.
   The heading is `Cadence::displayWeekLabel()` + "Contests" — "Week 0
   Contests" is a real string inside a split opening week — and it is
   SKIPPED when the calendar has no week, never the cards, never the door,
   never a substituted week. One Voice definition, `picks.contests.subheading`.

   The door renders in exactly two mutually exclusive places: here, for a
   reader with groups, and hoisted up beside the mode doors on a first run,
   where the two ways to play have to sit next to each other — both off the
   ONE `roomsOpen` read. A rooms-only reader has no My Groups block, so the
   tour's `seats` stop anchors on this section instead of stepping over
   itself.

   A room's `past` flag is read off its OWN Saturday against the card being
   sold (`Seats::isPast()`), never `week_id` alone, because a room keeps its
   URL forever and leaves the inventory when its week ends — and an ESPN
   week can hold two Saturdays. A played room is in neither section nor the
   switcher; `pastRooms` counts it and the sidecar's "Rooms you've played"
   door sends the reader to History.

   **The you-strip** sits above all of it, at the top of This week
   (`x-you-strip`, the standings component unchanged): rung, XP, Tallboys
   and wins. Below `sm` the app header does not render, so this is the
   only place a phone reader meets their own ladder on the screen the
   ladder is played on. Wins renders an em dash until a week has been won.
   Zero new queries — `rank`/`walletXp` are already read, Tallboys ride the
   memoized `walletTotals()` SUM, wins is a projection of `cards()`.

   **All in** replaces the ask when every entry is complete
   (`picks.allin.body`): a static emerald card, NOT animated, because it
   is a state rather than an event. Three conditions — seats held, nothing
   left to ask, and at least one entry actually in.

   First-run readers — meaning no PRIVATE groups, so one public seat does
   not suppress the pitch — get **Two ways to play**: "Start your own
   group" over the three `x-mode-door` tiles, then "Or take a seat this
   Saturday" over the Lobby door. The doors are still the ONLY create
   affordance (pass 3 drew that destination twice, as three doors and then
   a full-width card underneath them); what is new is that the block says
   what the doors are doors TO, and puts the weekly public alternative
   beside the choice instead of 600px below it.
6. **Last week** — the Monday payoff, compact: settled entries from the
   past seven days, each row carrying the Winner badge or, failing that,
   your place in the field ("2nd of 12", `places()`, History's own
   one-query pattern read only from this branch). A week you WON is called
   out above them by the emerald payoff banner
   (`picks.payoff.banner` / `_many`) — the house's second celebration, and
   its entrance is spent once per session against the wins themselves.
7. **The ladder**, one bordered row: rung name, tabular XP, an `h-1` bar and
   the climb line. `RankLadder` returns NULL at the top rung, so the climb
   line is skipped rather than drawn as a finished bar under a promotion
   that is not coming.

Everything on the This week tab is a projection of ONE `cards()` read (one
query per concern across all groups — contests, slates, my picks, my
entries, my wins), which itself stands on the Seats read the switcher
shares; `needsPicks` and the ribbon clock filter that collection and never
query. Section chips: a room or group visit lights **My Picks**, because a
reader inside one is a seated member playing, not somebody browsing.

**Since the Aug-29 overhaul and the Aug-31 pass**: the screen forks into
**This week | Results** on an `x-plate` (first-run readers keep the single
scroll; Results holds Last week, the rank ladder and the Season-history
door), and a finished entry says "Entry in" — or amber "Tiebreaker left"
when the question is the one thing open — instead of a fraction. The
sidecar (20rem from `lg`, the foot of the page below it) now holds only the
"Rooms you've played" door, the ladder and Season history on Results, and
"How this works".

## Two guided walks, one component, two columns

`livewire/tour.blade.php` runs BOTH: the app's first-run walk from Home
(nine stops, closing on the install with the detected browser's own steps
inside the card) and the PICKS walk (`week · seats · balance · room · how`),
added when Tallboys gained two sinks and a cooler worth explaining. They
differ in exactly three things — the step list, the copy those keys resolve,
and the column the finish stamps — so a second component would have been a
second copy of the spotlight geometry, which is the part with the scars on
it. The lists live on `App\Support\Tours`; Blade renders the copy blocks by
index and Alpine walks the spotlights by index, and both read ONE `$steps`
now, which is what makes a second walk safe.

**Two columns, and the distinction is the point.**
`users.picks_first_seen_at` is the ECONOMY's fact — it is what switches
Tallboys on and what the weekly top-off hangs off — while
`users.picks_tour_completed_at` is one walk's business. Folding them
together would mean a replay from Account re-triggered a grant, or a reader
who waved the coach marks away looked to the economy like somebody who had
never turned up. Its own Pennant flag (`picks-tour`), its own `UxSignal`
(one emitter per signal, always), and its own replay row in Account.

The `room` stop is shared with the Home walk because the pitch is identical
from either screen, but its BUTTON is not: from Home it opens Picks, and
from Picks — where the spotlight is already on the Lobby door — it opens the
store. A button to the screen you are standing on is a dead button.

## How this works is a side room off My Picks, not a fifth chip

`/picks/how` (`picks-how`) is the Picks area's reference screen: the
currency, the cooler, what every room costs, and the three modes. It exists
because the Tallboy economy put a SECOND thing to understand on top of
contest modes that were only ever explained inside the store — a reader
standing on their own week should not have to walk into the Lobby to find
out what the number in their header buys.

**A destination rather than a disclosure at the foot of My Picks.** That
screen already carries the week, the seats, the payoff and the ladder, and
the Lobby folded its own rules away for exactly this reason. It is reached
from a link row that renders on BOTH forks — the rules are looked up
mid-week as readily as on a Sunday — and it lights My Picks in the section
strip rather than taking a fifth slot in a four-chip row.

**Nothing on it is restated.** Mode rules are `ContestMode::ruleLines()`
through the same `x-mode-rules` card the Lobby uses; the shared laws are one
partial (`partials/pickem-laws`) both screens include; the room grid reads
`LobbyFlavor` and the engine; the cooler's three tiers come off
`GrantWalletEntry`'s constants and the reader's own row is MARKED, which is
what turns a rule into an answer. A rebalance moves this screen without
anybody editing it.

**The room grid is one stacked card per room, never a table.** Thirteen
rooms across three columns is a sideways scroll at 390px;
`ChromeConsistencyTest` bans `overflow-x-auto` outright, and the
non-negotiables put the design at 390 first. Cards widen to two columns
above `sm`. A dynamic-size room answers "On a full card" rather than a flat
yes, because its slate is as big as the Saturday allows and a thin one puts
±5 over the leverage ceiling — the same honesty `blurb($games)` applies to a
numbered pitch.

## The Lobby sells the open contests

`/lobby` (`lobby`) is the contest browser and nothing else — no picks, no
groups, no rank. The name and the URL stayed with the store on purpose: a
lobby is where you browse and enter contests.

- **A sticky band** pins the Saturday being sold: `Cadence::displayWeekLabel`
  left with the card's own date ("Week 0 · Sat Aug 29"), the open-room count
  right, then a micro-row saying WHEN that Saturday starts, then the
  room-type tabs. Opaque, `-mx-4 -mt-5` with the spacing moved inside so it
  has nothing to travel through, at `top-[var(--chrome-offset)]` (see
  `.ai/rules/css.md`). The WEEK'S range is never printed — 2026's Week 1
  opens on an empty 8/22, and no one is playing that date.

  The clock is `x-kick-clock` (`idle-prefix="First kick"`), fed by ONE
  aggregate over the open rooms' published slates — resolved off the
  relations `openRooms()` already eager-loads, mirroring
  `LobbyCatalog::shelves()` so two reads cannot disagree about the same
  Saturday. FUTURE-ONLY: a store whose games have all kicked shows no clock,
  because the actionable answer is the next kickoff. Null is no data and
  skips the row. It is the one query this screen added; a test pins it at
  exactly 1 with rooms open and 0 with none.
- **Shelves** (`LobbyShelf`, case order = display order): House rooms, Quick
  hits, Spotlight, Conference rooms. Headings are PLAIN in every register —
  people navigate by them — with the register line (`lobby.shelf.*`)
  render-guarded underneath.
- **Uniform rows** (`x-room-row`): mode tile, truncating name, ONE
  truncating pitch line, one tabular micro-line ("Shotgun · 10 games · 0 of
  20 seats"), Join. Mode identity is the tile plus the micro-line and never
  a right-hand chip: at 390px a chip and a button together starve the name.

  The pitch line came BACK on 2026-08-31, reversing the pass-4 decision that
  moved blurbs to the room screen. That decision was right about paragraphs
  and wrong about the shelf: ten flavored rooms shipped with ten
  personalities and the store rendered none of them, so two names sat over
  two identical rows. `LobbyFlavor::blurb()` when the room has a flavor,
  `ContestMode::blurb()` when it does not, both sized from the CONTEST's
  slate — capped at one truncating line, which is what keeps thirteen rows a
  shelf. Zero queries: an enum read of the loaded `flavor` column. Evergreens
  pass nothing — no Saturday, no pitch.

  With two seats or fewer left and the reader not already seated, the seat
  count becomes "{n} seats left" in WEIGHT, never color: rows repeat, the
  amber budget is one per viewport, and dark mode un-brands. A fact, not
  Voice. Stretched-anchor
  grammar from `x-game-card` (a button may not nest in an anchor) — the row
  opens the room, Join seats you in place. A row the reader is already
  SEATED in trades the primary Join for a flat "View picks" cue (`seated`,
  off `LobbyCatalog::shelves()`): the shelves are seat-inclusive, so the row
  has to tell "for sale" from "yours", and a CTA for a seat you hold is a
  tap that can only re-answer with the membership you already have.
- **Dashed closed rows** for catalog entries with no live room, saying "Not
  enough games this Saturday" — the preflight's own vocabulary, an
  instruction and never Voice. They are an INFERENCE from the sweep's own
  output: with nothing stocked at all, absence proves nothing, so an empty
  lobby dashes nothing either. A room the reader is SEATED in counts as
  stocked, which is why `Lobby::openRooms()` is seat-inclusive and flags
  rather than drops.
- **A framing line under the band, before the first shelf**: one plain
  sentence carrying the two facts a reader needs to tell a room from a
  group ("Public rooms — anyone can take a seat, and each one plays a
  single Saturday") over a render-guarded `lobby.intro.zinger`, which is
  where the OTHER half of the product gets pointed at. It sits under the
  band and never above it: the band is sticky with its container's padding
  cancelled, and anything inserted ahead of it is something for it to
  slide under.
- **Evergreen tables** sell after the Saturday shelves; an always-open lobby
  is not a Saturday product. Then a one-line cross-link to the wizard —
  "Want a season-long group? Private and invite-only — you run it, and the
  standings run all season", because "Rather run your own?" asked a
  question of somebody who had never been told the season-long thing
  exists — then
  **How it's played**, itself FOLDED into one disclosure since 2026-08-31 in
  the invite-code disclosure's exact grammar: sixty-five lines of foot matter
  stood between a shopper and the bottom of every visit on a screen whose job
  is to seat them in a room. Inside it, one expandable `x-mode-rules` card
  per mode reading `ContestMode::ruleLines()` (the same source as the docs),
  plus the shared law in one plain paragraph. Collapsed content is x-show,
  never removed, so a test asserts the stakes without driving the
  disclosure — and nothing was cut, only folded.

The whole screen is ONE inventory read (`Lobby::openRooms`) projected by
`LobbyCatalog::shelves()`; feasibility is never asked at render time, because
`resolve()`/`viableCount()` is a slate suggestion per row. Joining lands each
kind at its own address (`pickem.room` for rooms — the old clubhouse
double-hop is dead) and a race to a filled room answers with
`contest.room.full` in the lobby instead of an exception.

**Both routes stay outside the flag middleware, and neither redirects to the
other.** `/picks` used to 301 to `/lobby`; browsers cache a 301 forever, so a
redirect pointing back would loop on every dev machine holding the old one.
Guests and flag-off readers get the same `partials.pickem-promise` at both.

**The band is light since 2026-09-01**: white with a zinc-200 border
(zinc-900 with a zinc-800 border in dark), the grammar of every card under
it. It was a deep zinc surface in both modes, which read as one object
beside the branded team pages but gave an uploaded mark nothing to contrast
against — a dark icon on a dark band vanished, and the initials tile was a
white wash on a wash. The tile, the kind chip, the cog and the meta line were
repainted with it. The band holds ONE control, the commissioner's cog; the
Talk icon left the row for a gutter tab the same day, giving the title row
~44px back at 390, and a member's empty slot renders no wrapper at all.

The `x-group-hero` chip renders for BOTH kinds — `Public` for a lobby,
`Private` otherwise. It used to render for lobbies only, which made the mark
something some rooms wore and said nothing at all about the container a
private group is; a badge one side of a pair wears is a badge nobody reads
as a pair. A private group also gets the symmetric half of the room blurb
below the hero: its mode's blurb sized from the contest, over
`group.private.frame`. The invite landing's meta line leads with the kind
for the same reason — "Private group, all season · 4 members" /
"Public room · Week 1 · 3 of 20 seats" — because a link lands somebody who
has never seen the app on a name, a mode chip and a member count, none of
which say which thing they were invited to.

Both screens resolve THE CARD BEING PLAYED through `Slate::scopeOnCard()`,
never `where('week_id')` alone. An ESPN week can hold two Saturdays and
`slates` is unique on `(contest_id, saturday)`, so a group that carried a
Week 0 draft owns two rows inside one week — and the two screens used to
pick different ones: the clubhouse's `->first()` took the older row while My
Picks' `keyBy()` kept the last, so one said "no slate yet" while the other
showed a live card for the same week. A week with no Saturday at all matches
nothing rather than falling back to any slate in it.

A commissioner's clubhouse gates the build door on the SATURDAY, not on
the calendar: `SlateFeasibility::for()` asks whether this Saturday can seat
the group's mode, and a Saturday that cannot (Week 0's seven or eight games
against Shotgun's ten and the Woodshed's fifteen) takes the button away,
states both numbers and names the Saturday the ritual reopens on. A group
never downsizes the way a house Shotgun room does — its mode is a
season-long promise its members chose. The My Picks card drops its blue
"Build the slate" (and the deadline beside it) for the lobby's own words,
resolving the Saturday's pool ONCE for every card; the wizard itself
refuses the same way for a bookmarked URL, before it would have created a
draft nobody could publish. A null answer — no week, no Saturday — leaves
the door exactly where it was.

The room screen carries what the old `x-contest-card` used to: the flavor's
blurb (or the mode's) and its optional zinger render under the room hero,
where somebody who tapped a row decides whether to sit down. The blurb is
SIZED FROM THE CONTEST — `blurb($contest->mode->engine($contest->settings)
->slateSize())`, the same on the invite landing — because Shotgun's size is
frozen per Saturday and the room screen used to read "10 games, 10 points
each" over a Week 0 card of seven.

**The clubhouse went two-tab on 2026-08-30** (docs/plans/home-and-picks-
pass.md): **Slate | Standings** for both kinds, legacy `?view=season|members`
normalizing across in both hooks. Slate is pure play — the This-week table
left it and the first pickable card rose ~300px. A bare visit opens to
Standings once the entry is in and the card is live-through-final (explicit
`?view=` always wins; the answer is asked once, at mount), and the tab polls
every 30s only while a slate game is live, reading only our own database.

**The name is the switcher, since 2026-09-01.** The hero's `h1` gave way to
`x-group-hero`'s `title` slot, where the clubhouse renders `x-group-switcher`
in its `hero` variant — `currentColor` on the band, no ring, the name clamped
to two lines instead of truncated beside the mark and the one control at
390 — with an sr-only h1 beside it: the house shows no visible h1 off Scores,
and the name is a control now. Same rows as My Picks' switcher, the current
group bold; a lobby previewed without a seat, or a room whose Saturday is
played, is spliced in as a bare row so the trigger never reads "All my
picks" on a clubhouse. One Seats read, held in the screen's own
`#[Computed] seats()`, and slate-size independent, so the flat-query pin
still holds.

**One strip, four stops, since 2026-09-01.** The clubhouse briefly carried
TWO rows — a plate of Slate|Standings with an `x-gutter-tabs` of
Standings|Members|Invite beneath it — which stacked three levels of
navigation over the content once the area nav is counted, and printed the
word "Standings" on two of them. A reader cannot tell which row owns which
decision.

The plate is the one that went: `x-plate` is documented as two tabs and
throws above three, and four stops is exactly the case `x-gutter-tabs`
exists for ("more tabs than two-or-three"). So `$view` alone drives
**Slate · Standings · Members · Invite**, still `#[Url]` and still
normalized in both hooks. There is no `$pane`.

- **Slate** — pure play.
- **Standings** — `x-you-strip`, the week and season tables, the picks
  grid, and `x-mode-rules` sized from the contest as the scoring panel
  beneath the numbers it explains, then the Talk door.
- **Members** — the roster, the commissioner badge, the handoff, Remove
  and Leave. It was a collapsed disclosure at the foot of the standings,
  which put the one control that transfers a league behind a chevron.
- **Invite** — `x-invite-panel`, groups only. It moved off the top of the
  standings because it now carries a QR and three ready-to-send messages
  and was burying what people came for. The HERO's copy-link button went
  with it: a stop that owns the link, the code, a QR and three channel
  templates does not need a second, worse door beside the name.
  `GroupPageTest` guards that on the clipboard handler rather than the
  word "Invite" — the button and the tab label read identically to
  `assertSee`, and only the copy handler tells them apart.

A room gets **three** stops: `normalizedView()` sends `invite` back to the
standings for a lobby, so the strip and the content cannot disagree about
which stops exist. `?view=season` still folds to Standings; `?view=members`
is a live address again and lands where it says. `GroupPageTest` counts the
`group-tab-` keys and asserts there is no second strip — a two-strip
regression passes every content assertion, so the count is the guard.

**Names, not handles, inside a private group.** `x-standings-table` takes
`names`, true only when `! $group->isLobby()`, and the Members stop follows
the same seam. A private group is people who invited each other by text,
where a handle is the worse answer; a public room is strangers who walked in
off the lobby, and their legal names are not the room's to publish. The
seam is the KIND of room, never a preference, and `GroupPageTest` asserts it
both ways round on one fixture — a test that only checks the name appears
passes just as happily when the room prints it too.

## The invite landing is the acquisition funnel

`/join/{CODE}` (`pickem.join`) — the URL a group travels by, public like both
pick'em doors and for the same reason: the whole point is a GUEST tapping a
friend's link. The flag check lives in `mount()` (the flag scopes to the
user, so middleware would 400 every guest), and bounces outside the flag to
My Picks; the screen's own "Go to the Lobby" and "Find another room" buttons
correctly stay on the store. The preview shows the group's name, mode
identity and people BEFORE any wall; `?by={handle}` credits the inviter when
the handle is real and says nothing when it is not. The join tap writes
`url.intended` by hand (inside a Livewire action, `redirect()->guest()`
would capture `/livewire/update`) and walks guests through login/register —
both `redirectIntended` — landing them back seated in one tap. A dead code
gets words and a door, never a 404; a seated member skips the pitch straight
to their clubhouse; a full or already-played room states its condition
plainly with the Voice line for mood. Share surfaces (creation step 3, the
clubhouse's Invite stop) copy and share the LINK first with the code
kept beneath as the fallback — and rooms never advertise codes or /join
links at all: they are joined from the lobby.

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
