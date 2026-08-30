# ESPN feeds and the sync pipeline

What the ESPN APIs actually return, what they cost, and the rules every sync
job follows. All of it verified live rather than assumed — where a fact was
measured, the measurement is kept beside it so nobody re-probes a dead end.

See also [operations.md](operations.md) for queues, workers and the ops layer.

## Why the critical rules exist (the ESPN half)

**A team's ESPN "group" may be a division, not a conference.** Oregon's 2021
group is 54 ("Pac 12 - North", `isConference: false`) whose parent is 9
(Pac-12). `SyncTeams` resolves the parent — without it, conference standings
split into unrollupable halves and division restructuring looks like realignment.

**Never write a default when a feed returns nothing.** v3 defaulted standings to
zero on a lookup miss and overwrote 9-1 teams with 0-0. `EspnClient` returns
`null` for "no data"; callers must skip, not substitute.

**Read ESPN records and stats by NAME, never array position.** v3 indexed
`stats[0]`/`stats[1]` and broke whenever ESPN reordered.

## ESPN API facts (all verified live, not assumed)

- Per-week game requests silently truncate. Use date ranges.
- A season-wide range decodes to ~92 MB and blows PHP's 128 MB limit. `SyncGames`
  uses overlapping 30-day windows.
- A single-game `summary` payload (523 KB) is LARGER than a whole day's
  scoreboard (440 KB / 25 games). There is deliberately no single-game sync.
- Odds ride along inline on the scoreboard for upcoming games — free. But ESPN's
  opening line is not available and completed games return `odds: null`, so line
  movement cannot be backfilled; we freeze our first observation as `open`.
- `gameQuality` is retrospective and absent on unplayed games. Tiering must use
  `matchupQuality`.
- Only the CURRENT roster is published. `?season=2025` returns zero athletes.
- Recruiting resolves only at `/recruiting/{year}/athletes` (404s on every
  obvious guess) — see the Recruiting section below, which is the one feed where
  the cost was self-inflicted.
- There is no NIL endpoint. `NilNewsProvider` filters team news by keyword.

## Recruiting: 27,000 prospects for 31 requests

The recruiting table held **25 prospects of one class** for months. Nothing was
broken; two assumptions were, and both are the kind that fail silently.

**`limit` caps at 1000, and asking for more gets you 25.**

    /recruiting/2026/athletes            count=5193  pageSize=25    pageCount=208
    /recruiting/2026/athletes?limit=1000 count=5193  pageSize=1000  pageCount=6
    /recruiting/2026/athletes?limit=2000 -> silently ignored, back to 25

That silent fallback is why `EspnClient::MAX_PAGE_SIZE` clamps rather than
trusting the caller: over the ceiling, "fetch everything" becomes "fetch the
first page" with no error to notice.

**Every collection item is ALREADY the whole document.** Diffed an item against
its own `$ref` payload: the key sets are identical, nothing is missing. So
following the ref cost one request per prospect and bought nothing —
`paginate()` now takes `inline: true` to skip it. A full class went from ~5,200
requests to **six**. All eight classes, 27,178 prospects: **31 requests**.

Twenty-three classes are published, **2006-2028**. We hold 2021-2028.

**`alternateId` links a prospect to the player they became**, and it is why
history is worth holding — but the rate is nothing like the top of a class
suggests. Measured against athletes we actually hold:

    2021   82% of the top 1000   51% of the whole class
    2024   89%                   41%
    2026   30%                    4%   (they have not enrolled yet)

### `schools[]` is an interest list, not a visit list

Only **659 of 10,472** entries in the 2026 class carry a date — 6%. Most rows
are "this school was in the running". Hence `recruit_schools`, and hence the
team page can show who a school recruited and lost.

Three traps in it, all measured:

- **Seven rows carry the year 2205** — an ESPN typo for 2025, and MySQL's
  `timestamp` tops out at 2038-01-19. The column is a `date` and the sync drops
  anything outside `[class-3, class+1]` rather than guessing the intended year.
- **The unique cannot be `(recruit_id, team_id)`.** That column is nullable for
  schools we do not carry, and MySQL never matches NULL to NULL in a unique
  index, so those rows were re-inserted on every weekly run — verified, one
  recruit went from 21 interest rows to 22. It is keyed on `espn_team_id`, which
  is never null and also records WHICH school it was.
- **An Undecided prospect's schools all share status id 0.** Matching the
  commitment on status id alone would pick one of their visits at random and
  call it a signing, so `committedTeamId()` bails on a falsy status.

### Recruiting has its own position vocabulary

It is NOT the roster's. Recruits are labelled `QB-PP` (pro-style, 224 in 2026),
`QB-DT` (dual-threat, 85), `OG`, `OC`, `OT`, `OLB`, `TE-H` — plain `QB` has
exactly **one** prospect. A position filter built from roster labels finds
nobody. `/recruiting` derives its position menu from the recruits themselves
and orders it by `positions.parent_id` (70 offense, 71 defense, 72 special),
because recruits carry no `position_group`.

### A class ranking cannot be an average, and cannot be a total

ESPN's `recruiting/{y}/rankings` is an empty shell — `{id, name: "ESPN Class
Rankings"}` with no entries, every sub-path 404 — so it must be derived, and
both naive answers are wrong on real data:

    average alone   a school with ONE 77-grade signee ranked 3rd nationally
    total alone     West Virginia's 71 signees (61.1 avg) outranked LSU's
                    class containing the nation's #1 prospect

`App\Support\RecruitingClasses` sums the **top twenty**, which is the size of a
real class and what a recruiting service actually does. ESPN lists 40-70
"commitments" per school; the walk-on tail must not move a ranking. One place,
shared by `/recruiting` and the team tab, so the two can never disagree about a
team's rank.

### Two more things that bit

- **A conference scope must not be resolved against the recruiting class.**
  `team_seasons` stops at the newest season we hold, so `Scope::teamIds('fbs',
  2028)` is an EMPTY array — not "everyone" — and it excluded every committed
  prospect in the 2027 and 2028 classes. The screen asks the DATA for the newest
  membership year at or before the class; `currentYear()` is the wrong question
  because it falls back to config when no seasons are loaded.
- **Uncommitted prospects are 6-12% of a class** and belong to no team, so a
  scope filter has to admit them explicitly — the same escape hatch the
  scoreboard gives an unannounced fixture.

Dead ends, so nobody re-probes them: `/recruiting/{y}/teams`,
`/recruiting/{y}/schools`, `/recruits/{id}/analysis`, `/recruits/{id}/notes` all
404; a team document carries no recruiting ref; and `attributes` exposes
`fortyYrdDash`, `threeConeDrill` and `twentyYrdShuttle` which are **all sentinel
99** — the same "number that means no data" as `curatedRank`.

## Sync cost tiers

SCORES cost ONE request per minute total, regardless of how many games are in
progress or how many people are watching — that one scoreboard payload carries
every live game's score, clock, period and status, which is also everything
pick'em scoring needs. Respect the tiers in `SyncGames` and
`routes/console.php`; v3 burst to ~20 requests/second.

    live 0-1 · today 1 · current 1 · recent 2 · season 9

BOX SCORES are the other half, and they do not ride the scoreboard —
`cfb:summaries:live` sweeps every in-progress game every two minutes, one
request per game (see the game-summary section). A 30-game Saturday peak is
~15 req/min on top of the tiers, comfortably inside the 240/min budget.

**That budget is OURS, not ESPN's.** `ESPN_RATE_LIMIT` defaults to 240 and no
ESPN document or observed 429 sets it — it was chosen as ~5x below v3's
known-bad burst rate. What ESPN *does* enforce is a User-Agent allowlist; see
below.

Scale-to-zero MySQL means writes are not free: sync only writes rows that
actually changed (`fill` + `isDirty`), and public reads are cache-first. The
summary sync carries the same discipline in `game_summaries.scoring_plays_hash`
— scoring rows are replaced wholesale, so an unchanged payload must skip the
rewrite or the sweep churns every row all Saturday. It is a HASH rather than a
count or a last-sequence check because ESPN issues corrections that rewrite an
existing play, which neither of those can see.

## ESPN 403s a custom User-Agent on the site host

Measured 2026-08-06, interleaved so ordering and rate effects are ruled out —
the result tracks the header, not the sequence:

    curl/8.7.1                          200
    GuzzleHttp/7                        200
    python-requests/2.31.0              200
    CampusFootball/1.0 (+https://...)   403
    foo/1.0                             403
    Mozilla/5.0 ... Chrome/131 ...      403

Their edge allowlists known HTTP-client agents and refuses everything else,
browser strings included. **Host-specific**, which is why it hid: `core` and
`web` served 200 to the custom agent throughout, so rankings, recruiting,
coaches and team stats all kept working while `site` — the SCOREBOARD and
SUMMARY feeds, which is to say games and box scores — returned nothing.

And it fails SILENTLY. A 403 is not retried (correctly: the request is not
wrong, and repeating it burns allowance), so the client logs a warning and
returns null, and "never write a default when a feed returns nothing" does the
rest — `cfb:games` reported `0 changed, 1 requests` and exited 0, all day.
`config('espn.http.user_agent')` is `GuzzleHttp/7`, which is what Laravel's
client sends when no header is set, and `ESPN_USER_AGENT` overrides it without
a deploy if their policy shifts again.

## Never hardcode the current season

`App\Services\CfbCalendar` is the single source of truth for where we are in
the football year. Do not read `config('cfb.season')` in a screen and do not
select "the latest season" — a season exists in the database months before it
is played, so both land the user on an empty page.

ESPN's four season types are all synced, and their names are MISLEADING —
verified live, do not trust the labels:

    1 Preseason      2025-02-01 -> 2025-08-23   six months
    2 Regular Season 2025-08-23 -> 2025-12-13
    3 Postseason     2025-12-13 -> 2026-01-21
    4 Off Season     2026-01-21 -> 2026-02-01   eleven days

So ESPN's "Preseason" covers what a person calls the offseason, and its
"Off Season" is only the bridge between the playoff and the next cycle.
`SeasonPhase` is our own vocabulary; type 1 is split by proximity to kickoff so
the app never claims it is preseason in March. Ranges abut, so an instant on a
boundary matches two rows — containment prefers the types that carry games.

    $calendar->phase()                 preseason|regular|postseason|offseason
    $calendar->currentYear()           the season we are in or heading into
    $calendar->resultsYear()           the latest season that HAS games
    $calendar->defaultWeekNumber($y)   current week, else last week with games
    $calendar->rankingsYear($poll)     latest season that has THAT poll
    $calendar->pollYear()              latest season with ANY major poll
    $calendar->defaultPoll($year)      first major poll that HAS rows
    $calendar->availablePolls($year)   polls with rows, majors first
    $calendar->rankingReleases($y,$p)  every release, newest first

## Rankings come from the CORE api, not the site one

The site rankings endpoint NEVER returns the CFP rankings — asking it for week
16 gives the same five polls as week 1, and its `type=` parameter is silently
ignored. Only `core/seasons/{y}/types/{t}/weeks/{w}/rankings` exposes them.

Poll keys are derived from ESPN's numeric ranking id, never its `type` field:
AFCA Division II (11) and Division III (12) both report `type: "afca"`, which
merged two polls into one key.

Poll availability is real business logic, verified live for 2025:

    AP / Coaches   preseason poll, weeks 2-16, then final rankings
    CFP            weeks 11-16 only
    CFP Seedings   week 16 only, 12 teams
    divisional     drop out entirely by week 16

A "release" is a (season type, week) pair, not a week number — the preseason
poll and the final rankings are both "week 1" of their own season type, so a
selector keyed on number alone collides them.

## A game card's rank is ESPN's, then ours, and always AS OF KICKOFF

`games.home_rank`/`away_rank` hold ESPN's `curatedRank` and are what a card
shows whenever they exist — `SyncGames` re-patches them on every pass, so they
keep up as polls move. Two things about them:

- **99 is ESPN's "unranked" sentinel.** `SyncGames` already maps it to null on
  write; readers must still guard, because a card printing "#99" is the tell.
- **They are not always populated.** All 946 of 2026's games carry no rank on
  either side while the Coaches preseason poll is out and we hold all 25 rows.
  ESPN does not backfill a schedule when a poll lands — re-fetching week 1
  still returns 99 — so re-syncing does not fix it.

`App\Support\GameRanks` fills that gap from rankings we already hold:

    1. latest poll release published at or before KICKOFF
    2. best poll in it — CFP, then AP, then Coaches; else walk back a release
    3. unranked is null, never 99

Week 1 needs no special case: there is no regular week-1 release, so the latest
one at or before kickoff IS the preseason poll.

**POSTSEASON releases are excluded deliberately.** ESPN files the AP and Coaches
"Final Rankings" under postseason week 1, whose range opens Dec 13 — so a bowl
on Dec 20 would show a poll not published until January. Excluding them leaves
the last regular-season release, which is the CFP final and is what a bowl card
should carry.

The two sources agree where both exist: checked against 2025 week 12, ESPN's
curated value IS the CFP poll (20/8/3/2) rather than AP (19/7/3/2), and all 61
games of that week render identically either way. Which is why the ladder above
is CFP-first — it is ESPN's own.

Resolved per GAME, not per side: mixing ESPN's number on one team with ours on
the other turns a one-rank difference between polls into what looks like a bug.
Costs one lookup per RELEASE, so a 50-card slate is one query, not fifty — and
it reads `season_id` and `kickoff_at` straight off the row rather than needing
`week` eager loaded, because a card renders from six screens and requiring each
to remember a constrained eager load is how a missing column ships.

**A poll's columns are not the same from poll to poll.** Measured over every
stored row, and it decides what `/rankings` can render:

    ap / coaches    points always, first-place votes on ~10% of rows,
                    previous_rank on ~85% (a preseason poll has none)
    cfp             ZERO points, ZERO first-place votes, previous_rank
    cfp-seedings    zero points, zero votes, and NO previous_rank either

So a fixed column set prints an empty column through the whole playoff race and
twenty-five consecutive "NR"s all summer. Rankings renders the movement column
only when some row has a `previous_rank`, decided from the collection it has
already fetched rather than by another query. First-place votes ride in the team
cell instead of a column of their own, because only a handful of teams in any
poll have any and an almost-empty column spends width the team name wants.

**Coaches lands BEFORE AP, and the default has to follow.** Verified live on
2026-08-05: the only poll ESPN published for the entire 2026 season was the AFCA
Coaches preseason (ranking id 2, `type: usa`) at type 1 week 1 — no AP at all.
So `defaultPoll()` returns the first MAJOR poll that actually has rows, in
`Poll::major()` order (CFP, AP, Coaches), rather than "CFP else AP". Naming a
poll with no rows opens the screen empty while a real published ranking sits
one option away in the dropdown — the same failure as a Top 25 filter with no
poll behind it.

Its year cannot come from `rankingsYear('ap')` either, which is circular: in
August that answers LAST season, so every screen defaulting through it opens on
the wrong year. `pollYear()` asks about any major poll instead.

**A preseason poll needs its WEEK row to exist first.** `SyncRankings::season()`
loops the weeks we hold, so with no type 1 week 1 in `weeks` it never asks for
the preseason poll and reports 0 records while ESPN is serving 25. ESPN
publishes that week only when the poll is near, so the seasons step has to run
again before rankings — `cfb:sync --only=seasons` then `--only=rankings`. Worth
remembering every August; nothing about the failure points at weeks.

The distinction between `currentYear()` and `resultsYear()` is the important
one: in August they differ, and conflating them is what empties a dropdown.

Everything derives from date ranges, not from the `is_current` columns on
seasons and weeks — those exist but the sync never populates them, and a stored
flag goes stale the moment a scheduled job misses a run.

## `games.name` is never the bowl name

It only ever holds "A at B". The event's real name — "Rose Bowl Presented by
Prudential", "College Football Playoff National Championship" — is
`competitions[0].notes[0].headline`, and we discarded it until it was needed.
Verified live: 41 of 41 postseason events carry one, and the 11 playoff games all
begin "College Football Playoff", which is the ONLY way to tell a playoff game
from any other bowl. A heuristic on `name` matches nothing at all.

Stored as `games.note`; `Game::playoff()` and `Game::bowlsOnly()` read it.

## The postseason is one ESPN week, shown as two

`types/3/weeks` returns a single item called "Bowls" spanning Dec 13 to Jan 21
and holding all 46 games. The scroller splits it into **BOWLS** and **CFP**, and
each pill dates itself from its own games — using the shared week would put
"DEC 13" on a playoff that starts a week later.

Both pills share one `week_id`, so the scoreboard keys selection on the PAIR
(`week` + `bracket`). Setting the id alone leaves a stale bracket showing the
wrong half.

There is no `/bowls` route. Note the consequence, which is deliberate but worth
knowing: Scores has no season selector, so **historical** bowls are reachable
only through a team's schedule or a direct game URL, not by browsing.

## An unannounced fixture has a NEGATIVE team id

ESPN publishes every bowl and playoff game months ahead as "TBD at TBD", and it
does not use a null competitor to say so — it sends a real competitor whose team
id is **-1** (home) and **-2** (away), named "TBD". Conference championships are
the same until their standings resolve.

`games.home_team_id` is `mediumint unsigned` with a foreign key, so storing that
verbatim **throws**. Map any non-positive id to null: the column is nullable and
`x-team-link` already renders a null team as "TBD", so the fixture keeps its
date, venue and bowl name and only the matchup is blank — which is exactly what
the schedule is at that point. Same rule as the box-score pseudo-athletes: ESPN
uses non-positive ids for things that are not real entities.

**What made this expensive was the lack of isolation.** The throw aborted the
whole scoreboard request, so every event behind it in the payload was lost —
the 2026 season silently stopped at the first conference championship on Dec 4
and not one of its 43 bowl and playoff games was ever stored. The per-event
`try/catch` in `SyncGames::range()` is what stops one bad game costing a season;
treat a loop over a payload the same way the job fan-out treats a loop over
teams.

**A scope filter must not swallow them.** `Scope::teamIds()` matches on teams, so
a TBD fixture matches nothing and the entire postseason vanishes for the eleven
months when the date and venue are the only things on offer. The scoreboard adds
`orWhere(home IS NULL AND away IS NULL)` — a fixture with no teams cannot be
excluded on the basis of its teams. That is an escape hatch for UNANNOUNCED
games only; a real matchup outside the scope still filters out.

## News: clamped, rolling, and only one of its filters works

- `limit` is **clamped to 50** however much you ask for. There is no pagination
  parameter that lifts it.
- The GENERAL feed's window is about **six days**, so history from it is
  ACCUMULATED by syncing on a schedule. Nothing in the sync may delete.
- **`?team=` is honoured** and returns a genuinely different set — Georgia shares
  only 5 of 50 articles with the general feed. **`?athlete=` on the same
  endpoint is silently ignored.** One parameter can be trusted and its sibling
  cannot.
- **History CAN be backfilled, through the team parameter.** This file used to
  say it could not, and that was wrong: fanning `SyncTeamNews` across all 811
  teams in the current season took the archive from 50 articles to **6,153,
  spanning 2012-09-14 to 2026-08-05 — 13.9 years**, in one pass of 811
  requests. Each team's own feed reaches back years, not days; only the
  undifferentiated national feed is a six-day window. Worth re-running after
  any data loss, and the reason a team's news tab has real depth.
- Every article on the college-football path carries an `NCAA Football` tag, so
  no filtering is needed. Basketball tags appear as ADDITIONAL tags on
  multi-sport stories, not as off-topic articles.
- `categories[]` lists each team **twice** ("Georgia Bulldogs" and "University of
  Georgia", same `teamId`). Dedupe or the pivot doubles.

**Following a team is what fetches its news.** `FollowTeam` dispatches
`SyncTeamNews`, because a follow is the moment a team's feed becomes worth a
request — measured live, Alabama's feed held 25 articles we did not have and
Miami's 19. The job is unique on the TEAM, so a team gaining 500 followers after
an upset is one fetch, not 500. Note what it does and does not do: it DENSIFIES
the window, it does not extend it — the earliest article date barely moves.

Every write that creates a follow goes through `app/Actions`, never straight to
the relation, so the dispatch cannot be forgotten by a new caller.

## Article BODIES live on a fourth host, one request each

The news list carries a headline, a thumbnail and a link — never the story. The
body is only at `now.core.api.espn.com/v1/sports/news/{espnId}`, which is NOT
under the college-football path: it is league-agnostic and keyed on the article
id alone. Verified live over https (v3 called it over http) and it 404s on an
unknown id rather than returning an empty envelope.

Bounded exactly like the game summary, because the shape is identical — one
payload, and it cannot change once published:

    fetched ONCE, ever          a stored story makes every later view a pure
                                database read, so a shared article costs one
                                request no matter how many people open it
    throttled per ARTICLE       Cache::lock("espn:story:{id}", 60), not per
                                viewer

**A third of articles have NO body.** `Media` is a video or photo post — 78 of
our 212, and every one of eight sampled came back empty. So `story` being null
cannot mean "not fetched yet" or every view of every video post is a request:
`story_fetched_at` is what separates "asked, and there is nothing" from "never
asked". A failed request writes NEITHER — a transient 500 must not permanently
demote an article to a link.

**A story is not plain HTML.** It carries ESPN's own pseudo-tags — `<photo1>`,
`<inline1>`, `<video1>`, `<alsosee>` — which their renderer fills in and a
browser keeps as empty inline nodes. Observed across 18 stories: `alsosee` on 8,
`photoN` on 4, `inlineN` on 4. `<photoN>` resolves against `images[N]`, index 0
being the lead image the page renders itself. The rest are cross-promotion back
to espn.com and are dropped — **along with the paragraph wrapping them**, or the
prose is left with blank gaps; one conference roundup had seven.

`App\Support\ArticleStory` does that, then rewrites espn.com team and player
links to OUR pages (two queries per article, and `teams.id` IS the ESPN id),
then runs a deny-by-default tag and attribute allowlist. That last part is not
optional: this is third-party HTML rendered unescaped, which is the exact shape
of a stored XSS. Unknown tags are UNWRAPPED rather than deleted, so a wrapper
ESPN adds next season cannot silently eat a paragraph.

What is stored is ESPN's RAW markup; rendering happens at read time and is
memoized as a plain string. So improving the renderer never means re-fetching
200 articles.

Store the story in `mediumtext`, not `text`: measured 1.6-28 KB, and `text`
tops out at 64 KB — close enough to a long ranked-list feature that a silent
truncation is a real risk.

## Coaches: the roster names them, the coach sync makes them people

The roster feed delivers a coach as a name and nothing else. Everything else
comes from the core API's per-coach document — birthplace, career record, and
`coachSeasons[]` refs whose URLs carry the season years. Each season document
in turn carries `team.$ref` with the TEAM ID IN THE URL, so a coach's moves
between schools (Riley: Oklahoma 2017-2021, USC 2022-, verified live) parse
out of refs without resolving them — a coach costs 2 + 2N requests, not 2 + 3N.

- **Venue photos are probed, not fetched.** ESPN has them on its CDN but
  hands them to no feed a pregame screen can reach — `gameInfo.venue.images`
  lives in the summary payload, and an unplayed game has no summary. The URL
  is not derivable either: measured across six venues, three answer only
  under `day/interior`, one only under `day`, two under both, and one has
  none. So `cfb:venues` HEADs both patterns once per venue and stores only a
  200; `venues.image_checked_at` separates "asked, and there is nothing" from
  "never asked", which is what stops the 93 photoless venues being re-probed
  every run. 149 of 242 have one, so the game-information card must read
  correctly without it.
- **There is no coach headshot endpoint.** `players/full/{id}.png` resolves
  only where a coach's id matches their old player id (Smart yes, Riley no).
  One HEAD against the CDN — not the API, so not against the rate ceiling —
  stored only on 200. Every surface must look right without one.
- **ESPN writes coach birthplaces with FULL state names** ("Montgomery,
  Alabama") while athletes carry codes ("TX"). `SyncCoaches` normalizes to
  the two-letter form on write so a search list never shows both formats.
- A season whose record 404s stores the tenure row WITHOUT a record — skip,
  never default. A season whose team we do not know stores nothing.
- Coach pages route by ID, matching athletes — no slug column, and 326
  athlete slugs already collide.
- The schedule runs `cfb:coaches --current` weekly in season: only the
  latest season changes, because published career history never does.

## The game summary is the only source of a box score

Box scores, scoring plays and drives exist in exactly one payload — `summary` —
and it is 544 KB, LARGER than a whole day's 25-game scoreboard. So it is the
only single-game fetch in the app, and it is bounded twice over:

- A **final** game is fetched once, ever. Its summary cannot change, so
  `game_summaries.is_final` short-circuits every later page view to a pure
  database read.
- A **live** game is fetched at most once per 60s staleness window, keyed on
  the GAME rather than the viewer.

**Nothing fetches this inline.** The game page dispatches `FetchGameSummary`
and renders from the database (the athlete game-log pattern); a gameday sweep
(`cfb:summaries:live`, every two minutes) keeps every in-progress game
hydrated whether or not anyone is watching it, so opening a game never shows a
box score as stale as the last viewer left it.

**Three layers keep concurrent viewers from stacking fetches**, and each
catches a race the others cannot:

    ShouldBeUnique          collapses simultaneous DISPATCHES (a page full of
                            viewers plus the sweep) into one queued job.
                            Does NOT apply inside Bus::batch — batched jobs
                            skip unique locks entirely
    in-handle isStale()     a copy that sat queued while another source
                            refreshed the game becomes a no-op instead of a
                            request. `force: true` skips this, and only the
                            just-final dispatch and the backfill carry it
    released Cache::lock    two workers genuinely executing at once for one
                            game — the backfill-beside-live case uniqueness
                            cannot see. RELEASED in a finally, not expired:
                            its never-released predecessor silently swallowed
                            any fetch made within a minute of the last, the
                            same bug the game-log lock had

`SyncGameSummary::isStale()` is game-aware where the model's own is not:
"a final summary never changes" holds only while the GAME agrees it is final,
and they disagree in both directions — a completed game with a non-final
summary means the just-final fetch was swallowed, and a live game with a final
summary means ESPN flipped a game back after briefly reporting it complete,
which would otherwise freeze that box score for the rest of the game.

**There are exactly two queues, split by latency class**, since a
thousand-game backfill must not starve a Saturday: `live` (sweep, view boost,
just-final) and `default` (everything else — game logs, coaches, team news,
`cfb:summaries` batches, and the bulk mail sends). A third queue named
`backfill` carried the batches until 2026-08-30 and was removed: it bought no
isolation the `live`/`default` split did not already buy, and it cost a third
managed queue plus a third name in every `--queue=` line and runbook. Workers
want SMALL concurrency — `ThrottleEspn` RELEASES a job when the shared 240/min
window is spent, so adding workers past ~3 on `default` lowers throughput
rather than raising it.

A queue name fails silently in both directions, which is why the split is
pinned by a source sweep (`QueueNamesTest`) rather than left to review: a job
dispatched onto a name no worker consumes never runs and never errors, and
`Bus::fake()` asserts happily against a queue that does not exist.

**Production queues ride Laravel Cloud managed queues, and deploying one sets
`QUEUE_CONNECTION=cloud` — do not set it back.** Each of the two names is
its own managed queue (Flex, max 2 workers; 512 MiB where FetchGameSummary
runs, because it decodes a 544 KB payload). What must NEVER move off redis is
`CACHE_STORE`: the limiter window, the in-flight locks and the uniqueness
locks all ride the cache store, and managed-queue workers are separate
instances — split the cache and every no-stacking guarantee above silently
voids, one limiter and one set of locks per worker. Locally the queue stays
redis; `aws/aws-sdk-php` is required by the cloud driver at deploy time.
`queue:failed`/`queue:retry` do not exist for managed queues — failed jobs
live in the Cloud dashboard's Queues tab.

`GameScoreChanged` and `GameWentFinal` (dispatched from `SyncGames::store()`,
after save, never on a first insert) are the pick'em subscription points — a
contest recompute listens there rather than polling. They carry scalars, never
the model.

## A player's game log is POLLED, and Saturday is not like other days

The game log is the one genuinely per-athlete feed, so bulk syncing it would
cost one request for each of 34,836 players. Opening a player page therefore
DISPATCHES `FetchAthleteGameLog` rather than fetching inline — the page renders
what we already hold and returns in ~92ms instead of waiting on ESPN.

Freshness is `athletes.game_log_fetched_at`, and the window depends on the day:

    Sun-Fri   24 hours     nothing is moving
    Saturday  15 minutes   the numbers actually change

Fifteen minutes is a per-ATHLETE ceiling — four requests an hour for a player
somebody is watching, none at all for the rest of the roster — so it sits an
order of magnitude under the live scoreboard tier and well inside the 240/min
allowance.

**Saturday is decided in `config('cfb.timezone')`, never UTC.** A UTC Saturday
opens at 8pm Friday Eastern, which would put Friday night's games on the gameday
cadence and Saturday night's on the 24-hour one — exactly inverted, and only
ever visible in the evening.

Three rules the polling has to keep, all the same shape as `articles.story`:

- **The timestamp records that we ASKED, not that we got rows.** Most athletes
  never record a stat, so reading an empty `athlete_game_stats` as "never
  fetched" dispatches a job on every view of every one of them, forever.
- **An empty answer still stamps; a failed request does not.** A transient 500
  must not permanently demote a player to "no stats" — leaving the timestamp
  null is what makes the next view try again.
- **Persisted, not cached.** A `cache:clear` would otherwise re-open the tap on
  all 34,836 at once.

The job is unique on the ATHLETE, so a player trending after a big game is one
request rather than one per viewer — verified live: three page views, one queued
job. The service keeps a 60-second in-flight lock as well, deliberately shorter
than the gameday window so it cannot veto the cadence it exists to protect.

The page's two empty states are keyed on the TIMESTAMP, not on the log being
empty: "Fetching…" (with a `wire:poll` that reads only our own database) until
the first answer lands, then "No game log" forever after. Keyed on emptiness, a
player with no stats would sit under a spinner that never resolves.

**A "Refresh" button is offered only when nothing is outstanding**, and forces
past the staleness check — the whole point is a log that is not due one.
Offering it while the page-load job is still in flight invites a second request
for the answer already on its way and reads as though the first one failed.

### An in-flight guard must be RELEASED, not given a TTL

That lock was `Cache::add($key, true, 60)` with no release, which made it a
60-second freshness gate wearing an in-flight label. It silently swallowed any
hand-asked refresh made within a minute of the last fetch: no request, no stamp,
so the page had no "it came back" signal to wait for and spun until its own
30-second ceiling. It looked like a hang with a healthy queue worker behind it.

`Cache::lock()` acquired for the duration of the fetch and released in a
`finally` blocks only genuine concurrency. Redundant repeats on the unforced
path were never this lock's job anyway — `FetchAthleteGameLog` re-checks
staleness before spending a request.

The test that "proved" the old behavior called the service three times in a row
and asserted one request. Sequential calls are not concurrent viewers, so it was
passing for the wrong reason and pinned the bug in place. It asserts through the
JOB now, which is where the guarantee actually lives.

**The page's "did it come back?" signal compares second-resolution stamps**, so
it also treats a stamp at or after the moment it queued as landed — `timestamp`
has no sub-second precision, and a refresh landing inside a second of the last
one would otherwise look like it never arrived.

## National leaders and ranks are already computed for us

- `core/seasons/{y}/types/{t}/leaders` returns **13 categories × 100 athletes in
  ONE request**. The site equivalent 404s — core is the only source, same trap as
  the CFP rankings.
- It spans **every division** (245 teams for 2025 vs 136 in FBS), so a
  leaderboard must scope through `team_seasons.classification`.
- Team `statistics` carries a **national `rank` on every stat**. Keep it — the
  national stats screen is then a sort, not a computation over 136 teams.

## Leaderboards are DERIVED, not read from ESPN's leaders feed

ESPN's national leaders endpoint spans every division, and only about half its
top 100 is FBS. Read directly, a scoped leaderboard breaks three ways: ranks go
non-contiguous (1, 3, 4, 9...), "top 100" can only ever return ~55, and a
conference collapses outright — **the MAC had FOUR players** in the national top
100 for passing yards.

`AggregateAthleteStats` folds `athlete_game_stats` into `athlete_season_stats`
instead. Zero ESPN requests; it is arithmetic over box scores we already hold.
The MAC goes from 4 rows to 43, ranked 1..N. Validated before being trusted:
our sum for Drew Mestemaker's 2025 regular season is 4129, which is exactly what
ESPN reports.

Four rules the aggregation must keep:

    SUM      counting stats
    MAX      longRushing, longReception, longPunt... a season's longest run is
             the longest single run, not the total of every game's longest
    RATE     recomputed from summed components. Averaging per-game averages
             weights a 1-carry game like a 30-carry one
    DROP     adjQBR is a proprietary model, not arithmetic. Approximating it
             would be inventing a number

Rate leaderboards need a minimum-attempts floor or they are won by whoever
attempted once.

**`season_type = 0` means the whole year, bowls included.** ESPN's headline
leaders are cumulative — its stats page reports 4,379 for the passer whose
regular season was 4,129 — so the screens read type 0. It is stored as its own
row rather than summed at read time, because rate stats cannot be added.

`national_leaders` stays as a cross-check, the same dual-source discipline the
standings reconciler uses.

### `interceptions` means two opposite things

It exists in the `passing` category (thrown — bad) and the `interceptions`
category (caught — good). Same key, opposite meaning. A leaderboard keyed on the
stat name alone ranks quarterbacks by how often they were picked off and calls
them leaders. Always pair a stat with its category.

### Top 25 is a TEAM filter

Right on Scores — "the games that matter". Wrong on a leaderboard, where it
silently means "the leading rusher among 25 teams" and reads as national. The
scope filter takes `:top25="false"` on Stats and Leaders, and both screens
rewrite the value on mount AND on update so a bookmarked or carried-over
querystring cannot reintroduce it.

## Season id is not chronology

`resultsYear()` once read `Game::max('season_id')`, which worked only while
seasons happened to be inserted in year order. Backfilling 2021-2024 gave those
older seasons HIGHER ids and moved every default season in the app backwards.
**Order by `year`.**

Two different questions, and conflating them empties a screen:

    resultsYear()      latest season with games PLAYED     — standings, rankings
    scoreboardYear()   season we are in or heading into    — scores

In August they differ. A scoreboard on `resultsYear()` shows last season's bowls.

**`TeamGlance::year()` is the third answer, and it is the one league-wide TEAM
facts use** — records, conference names, standings positions, conference sizes,
the FBS picker. It is `scoreboardYear()` whenever we HOLD that season, falling
back to `resultsYear()`. Both halves are load-bearing:

- it was plain `resultsYear()`, which put a finished record on every home
  glance card all offseason, beside a conference the team may since have left
  — and disagreeing with the team page one tap away, which opens on
  `scoreboardYear()`. Two screens naming different seasons for one team is the
  bug; which season is right is downstream of that.
- the fallback is not defensive. A season exists in the database months before
  it is played but not before it is SYNCED, and pointing these maps at a year
  with no `team_seasons` rows empties every one of them at once.

`Remember::filled`, not `Cache::remember`: a lookup running while the season's
sync drains would otherwise pin "not held" for the TTL and keep the whole app
a year back. `/teams` calls `TeamGlance::year()` rather than re-deriving, so
the cards and the list cannot name different seasons.

`TeamGlance::flush()` must clear the YEAR memo as well as the map memo, or a
test inherits the previous test's resolved season and reads every map for it.

**Verify a fix like this by breaking it back.** `TeamGlanceYearTest` was written,
passed, and then run again against a one-line revert to `resultsYear()` — three
of its five tests fail there and the two fallback tests correctly still pass.
A test for a default-season bug is exactly the kind that passes for the wrong
reason, because the fixture has to place "now" inside a season that is
SCHEDULED but unplayed, and that needs real games: `scoreboardYear()` asks
whether the season has a schedule at all, `resultsYear()` asks for a COMPLETED
game, and with neither present both fall through to the config default and the
test measures nothing.

**A screen that lets you PICK a season must feed the selector the same
question it defaults on.** The team page defaulted with `resultsYear()` and
built its year dropdown from it too, so from February to kickoff it showed a
finished schedule and did not even OFFER the season 946 already-synced games
were sitting in. It opens on `scoreboardYear()` now.

**Where the data genuinely does not exist yet, fall back and SAY SO.** Stats,
standings and leaders only exist once games are played, so the team page's
Stats tab shows the most recent season that has them under a label —
"2026 hasn't kicked off yet, so these are 2025 numbers" — rather than an
empty state for a season that has not started. That is the same call
`rosterYear()` already makes for ESPN's current-roster-only limitation, and
it is the right shape for any preseason screen: schedule and roster are real
now, results are not.

Likewise a week selector must key on **week id**: the postseason's "Bowls" is
also week 1, so keying on number collides it with the season opener. And week
date ranges ABUT — week 1 ends the day week 2 starts — so subtract a day before
displaying a range.

## Fan out for isolation and latency, not for throughput

Steady-state load is about **1,600 requests a week** — under seven minutes of
request time against a 240/min ceiling. Parallelism buys essentially nothing
day to day. Running an army of workers would idle six days out of seven.

What queueing actually buys, and why every fan-out here exists:

    ISOLATION   one team failing must not take the other 135. Not
                hypothetical — a single historical athlete with an unknown
                position id aborted the whole 2022 stats backfill.
    LATENCY     SyncGames dispatches FetchGameSummary the moment a game flips
                to completed, so a Saturday 11pm final has its box score in
                about a minute instead of at 05:00 the next morning.
    MEMORY      one payload per job instead of a thousand in one process.

So: **size workers for the one real burst** — Saturday evening, ~60 finals
arriving together — and let them scale to zero the rest of the week. Managed
queues are the right fit precisely because they do that.

### What must NOT be decomposed

    cfb:games --tier=live    ONE request covers every live game. Splitting it
                             per-game takes a Saturday from 1 req/min to ~50.
                             This is the v3 failure the design exists to avoid.
    national leaders         already one request for 1,300 rows
    news (general feed)      already one request

Decomposing something that is already a single request is strictly worse. Fan
out by natural unit only where the unit count is high: per game (~960/season),
per team (136), per week (17), per conference (11).

## Don't re-sync what cannot have changed

`SyncRankings::season()` re-read all 18 weeks on every scheduled run — ~126
requests, twice a week, to learn ONE new week of polls. Published rankings never
change retroactively. The schedule calls `current()` (6 requests); `season()`
survives for backfills only.

Worth checking the same question of any sweep before scheduling it: what new
information does this run actually obtain?
