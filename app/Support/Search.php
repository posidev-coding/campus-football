<?php

namespace App\Support;

use App\Models\Athlete;
use App\Models\Coach;
use App\Models\Conference;
use App\Models\Game;
use App\Models\Recruit;
use App\Models\Team;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use ReflectionMethod;

/**
 * Global search over teams, players, coaches, conferences and games — shared
 * by the Home panel, the /search page and the ⌘K palette, so none can drift.
 *
 * ALL OF YOUR WORDS, AND WHEN NOTHING HAS ALL OF THEM, WHATEVER HAS THE MOST.
 * That sentence is the whole contract. It replaced Scout's database engine,
 * which matched the ENTIRE query as one `LIKE` — so a second word that was not
 * in the name took results to zero with no partial credit, word order was
 * load-bearing ("aguilar joey" found nobody), and the app had no way to say "I
 * found Joey Aguilar, just not that phrase".
 *
 * Scout is still what DECLARES the search surface: `toSearchableArray()` says
 * which columns are searchable and `#[SearchUsingPrefix]` says how, and both
 * are read off the model here rather than restated. The engine is what went.
 *
 * Everything runs against our own MySQL — no external service, no index to
 * sync — and search stays the fastest interaction in the app. There is no text
 * scoring beyond counting matched words, which is right, because relevance
 * here is DOMAIN knowledge: a live game beats a 2021 one and a current starter
 * beats a 2019 transfer whatever the strings look like. Each group states that
 * order in its own `order` closure, and it decides every tie.
 *
 * Ranked teams float by a PHP re-sort of the fetched page rather than SQL:
 * rank lives in TeamGlance's cached map, and every ranked team is FBS, so the
 * FBS-first SQL order has already pulled them into the page being sorted.
 */
class Search
{
    /** Below this a query matches most of the database and is not useful. */
    public const MIN_LENGTH = 2;

    /**
     * Words past this are dropped.
     *
     * Every term is a `LIKE` against every searchable column, so cost grows
     * with the sentence. Six is past any real search and well short of a
     * pasted paragraph — and a question typed into the box, which is now a
     * thing people do, is exactly how a paragraph gets here.
     */
    private const MAX_TERMS = 6;

    /** Fetched on the fallback pass so relevance has rows to sort. */
    private const FALLBACK_PAGE = 5;

    /**
     * How many words a row must match before the fallback will show it.
     *
     * TWO, and the number is the whole quality of that pass. At one, "Rose
     * Bowl" filled the Players group with everyone named Rose and the Teams
     * group with Bowling Green — every row honestly matching a word, and not
     * one of them what anybody asked for. At two, a row has to corroborate
     * itself, and a two-word query gets no fallback at all because "two of
     * your words" is already what the first pass required.
     */
    private const MIN_FALLBACK_MATCHES = 2;

    /**
     * Words that carry no discrimination, removed before matching.
     *
     * This is not tidiness. `How many passing yards did Joey Aguilar throw?`
     * put **Adam Howanitz** at the top of Players — "How" is a legitimate
     * prefix match on a real surname — and pushed the person actually named in
     * the question down the list. The answer layer taught people to type
     * questions into this box, so the box has to stop treating the question
     * scaffolding as evidence.
     *
     * `at` is in here and is worth its own note: it appears in every game name
     * we hold ("Alabama at Georgia"), so keeping it would make the first pass
     * demand a word that discriminates nothing.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'been', 'by', 'did', 'do',
        'does', 'for', 'from', 'had', 'has', 'have', 'how', 'in', 'is', 'it',
        'its', 'many', 'me', 'much', 'of', 'on', 'or', 'the', 'their', 'this',
        'to', 'was', 'were', 'what', 'when', 'where', 'which', 'who', 'whom',
        'whose', 'why', 'with',
    ];

    public static function tooShort(string $query): bool
    {
        return mb_strlen(trim($query)) < self::MIN_LENGTH;
    }

    /**
     * `%` and `_` are LIKE wildcards; typed literally they should match
     * literally, not blow the query open.
     */
    public static function term(string $query): string
    {
        return addcslashes(trim($query), '%_\\');
    }

    /**
     * The words in a query, escaped and bounded.
     *
     * Trailing punctuation comes off each one so `yards?` matches `yards` —
     * the answer layer taught people to type questions in here, and a question
     * mark welded to the last word would make that word match nothing.
     *
     * Single characters are dropped: they match most of the table and only
     * dilute the ranking. Two is already the floor for a whole query.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        $words = preg_split('/\s+/', trim($query)) ?: [];

        $terms = [];

        foreach ($words as $word) {
            $word = self::term(trim($word, " \t\n\"'?!.,;:()[]"));

            if (mb_strlen($word) >= 2) {
                $terms[] = $word;
            }
        }

        $content = array_values(array_filter(
            $terms,
            fn (string $term): bool => ! in_array(mb_strtolower($term), self::STOPWORDS, true),
        ));

        /*
         * Only if something is left. "Who is who" is all scaffolding, and a
         * reader who typed it should get whatever those words find rather than
         * an empty screen that looks like the search broke.
         */
        return array_slice($content === [] ? $terms : $content, 0, self::MAX_TERMS);
    }

    /**
     * "Every word matches at least one of these columns" — for the roster
     * FILTER boxes on Players and Recruiting, which are not this class's own
     * groups but want the same word splitting.
     *
     * AND only, and no fallback: those screens filter a list somebody is
     * looking at, and a filter that quietly widens when it fails to match is a
     * filter nobody can trust. Finding nothing is a legitimate answer there.
     *
     * @param  Builder<Model>  $query
     * @param  list<string>  $columns  qualified column names
     */
    public static function everyTerm(Builder $query, array $columns, string $raw, bool $prefix = true): void
    {
        self::clause($query, $columns, $prefix ? $columns : [], self::terms($raw), all: true);
    }

    /**
     * FBS first, then ranked, then alphabetical.
     *
     * @return Collection<int, Team>
     */
    public static function teams(string $query, int $limit = 6): Collection
    {
        $year = TeamGlance::year();

        $teams = self::run(Team::class, $query, $limit,
            columns: ['id', 'slug', 'location', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark'],
            // NULLs sort last under DESC, so a team with no season row lands
            // after every classified one.
            order: fn (Builder $q) => $q
                ->orderByRaw(
                    "(select ts.classification = 'FBS' from team_seasons ts where ts.team_id = teams.id and ts.season_year = ?) desc",
                    [$year],
                )
                ->orderBy('display_name'),
        );

        $ranks = TeamGlance::ranks();

        return $teams
            ->sortBy(fn (Team $team) => $ranks[$team->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Active players first, then whoever played most recently — search used to
     * rank players who left years ago above current starters.
     *
     * @return Collection<int, Athlete>
     */
    public static function players(string $query, int $limit = 6): Collection
    {
        return self::run(Athlete::class, $query, $limit,
            columns: ['id', 'slug', 'display_name', 'headshot_url', 'birth_city', 'birth_state', 'is_active'],
            with: [
                'latestSeason.team:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                'latestSeason.position:id,abbreviation',
            ],
            // `latest_season_year` is the denormalized column the season rows
            // stamp on save — this was a correlated MAX() subquery per matching
            // row, re-derived on every keystroke. NULLs sort last under DESC,
            // the same place MAX() put them.
            order: fn (Builder $q) => $q
                ->orderByDesc('is_active')
                ->orderByDesc('latest_season_year')
                ->orderBy('display_name'),
        );
    }

    /**
     * Whoever coaches NOW first — same idea as players.
     *
     * @return Collection<int, Coach>
     */
    public static function coaches(string $query, int $limit = 4): Collection
    {
        return self::run(Coach::class, $query, $limit,
            with: ['latestSeason.team:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark'],
            order: fn (Builder $q) => $q
                ->orderByDesc('latest_season_year')
                ->orderBy('display_name'),
        );
    }

    /**
     * Real conferences before ESPN's divisions and groupings — only 79 of 118
     * rows are conferences at all.
     *
     * @return Collection<int, Conference>
     */
    public static function conferences(string $query, int $limit = 4): Collection
    {
        return self::run(Conference::class, $query, $limit,
            order: fn (Builder $q) => $q->orderByDesc('is_conference')->orderBy('name'),
        );
    }

    /**
     * Live first, then upcoming, then finished — and within each, whatever is
     * nearest to now. A search for a team during their game should put that
     * game on top.
     *
     * @return Collection<int, Game>
     */
    public static function games(string $query, int $limit = 5): Collection
    {
        return self::run(Game::class, $query, $limit,
            with: [
                'homeTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                'awayTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
            ],
            order: fn (Builder $q) => $q
                ->orderByRaw("case
                    when completed = 0 and status in ('in', 'halftime', 'end-period') then 0
                    when completed = 0 and kickoff_at >= now() then 1
                    else 2
                end")
                ->orderByRaw('abs(timestampdiff(second, now(), kickoff_at))'),
        );
    }

    /**
     * Prospects who have NOT enrolled yet.
     *
     * Scoped to `athlete_id IS NULL`, and that is the load-bearing part. About
     * half of an older class eventually appears on a roster we hold, and those
     * people are already found by players() — surfacing them here too would put
     * one person in a result list twice under two headings, with the two rows
     * pointing at different places. Recruiting covers the ones a player search
     * cannot reach.
     *
     * Newest class first, then national rank: an unranked 2028 sophomore with a
     * matching surname is less interesting than a ranked 2027 signee. Unranked
     * prospects sort last rather than first, which `order by rank` alone would
     * do with nulls.
     *
     * @return Collection<int, Recruit>
     */
    public static function recruits(string $query, int $limit = 4): Collection
    {
        return self::run(Recruit::class, $query, $limit,
            with: [
                'committedTeam:id,slug,location,display_name,short_display_name,abbreviation,logo,logo_dark',
                'position:id,abbreviation',
            ],
            filter: fn (Builder $q) => $q->whereNull('athlete_id'),
            order: fn (Builder $q) => $q
                ->orderByDesc('recruiting_class')
                ->orderByRaw('national_rank is null, national_rank')
                ->orderBy('display_name'),
        );
    }

    /**
     * One group's rows: everything that matches every word, or failing that,
     * whatever matches the most of them.
     *
     * The second pass runs ONLY where the screen would otherwise show nothing,
     * so it can never widen a search that was already working — which is what
     * keeps "Rose Bowl" returning Rose Bowl games rather than every bowl.
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $columns
     * @param  list<string>  $with
     * @param  (Closure(Builder): mixed)|null  $filter
     * @param  (Closure(Builder): mixed)|null  $order
     * @return Collection<int, Model>
     */
    private static function run(
        string $model,
        string $query,
        int $limit,
        array $columns = ['*'],
        array $with = [],
        ?Closure $filter = null,
        ?Closure $order = null,
    ): Collection {
        if (self::tooShort($query)) {
            return collect();
        }

        $terms = self::terms($query);

        if ($terms === []) {
            return collect();
        }

        [$searchable, $prefixed] = self::surface($model);

        $build = function (bool $all) use ($model, $searchable, $prefixed, $terms, $columns, $with, $filter): Builder {
            $q = $model::query();

            self::clause($q, $searchable, $prefixed, $terms, $all);

            if ($filter !== null) {
                $filter($q);
            }

            return $q->select($columns)->with($with);
        };

        $first = $build(all: true);

        if ($order !== null) {
            $order($first);
        }

        $rows = $first->limit($limit)->get();

        // A two-word query gets no second pass: "at least two words" is
        // exactly what the first pass already demanded of it.
        if ($rows->isNotEmpty() || count($terms) <= self::MIN_FALLBACK_MATCHES) {
            return $rows;
        }

        /*
         * Nothing has all of them. Rank by how many each row DOES have, and —
         * this is the ordering that matters — apply it BEFORE the group's own
         * ordering, so the domain rules become the tiebreak rather than being
         * overruled. A query builder emits `order by` in call order, which is
         * the only reason the sequence here is load-bearing.
         */
        [$relevance, $bindings] = self::relevance($searchable, $prefixed, $terms);

        $q = $build(all: false)
            ->selectRaw($relevance, $bindings)
            // Corroboration, not a single lucky word. HAVING rather than WHERE
            // because it reads the computed column, and no GROUP BY is wanted
            // or needed for that in MySQL.
            ->having('relevance', '>=', self::MIN_FALLBACK_MATCHES)
            ->orderByDesc('relevance');

        if ($order !== null) {
            $order($q);
        }

        return $q->limit($limit * self::FALLBACK_PAGE)->get()->take($limit)->values();
    }

    /**
     * The searchable columns of a model, and which of them match by prefix.
     *
     * Read off the model rather than restated here, so `toSearchableArray()`
     * and `#[SearchUsingPrefix]` stay the single declaration of the search
     * surface — the same two things Scout's own engine reads.
     *
     * The prefix strategy is not cosmetic: Athlete and Recruit carry it, and it
     * is what keeps `athletes_display_name_index` usable across 34,000 rows.
     * `LIKE 'agu%'` can walk an index; `LIKE '%agu%'` cannot.
     *
     * @param  class-string<Model>  $model
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function surface(string $model): array
    {
        $instance = new $model;
        $table = $instance->getTable();

        $qualify = fn (string $column): string => $table.'.'.$column;

        $searchable = array_map($qualify, array_keys($instance->toSearchableArray()));

        $attributes = (new ReflectionMethod($model, 'toSearchableArray'))
            ->getAttributes(SearchUsingPrefix::class);

        $prefixed = $attributes === []
            ? []
            : array_map($qualify, $attributes[0]->newInstance()->columns);

        return [array_values($searchable), array_values($prefixed)];
    }

    /**
     * AND across terms, OR across columns — or OR across both when `$all` is
     * false.
     *
     * @param  Builder<Model>  $query
     * @param  list<string>  $columns
     * @param  list<string>  $prefixed
     * @param  list<string>  $terms
     */
    private static function clause(Builder $query, array $columns, array $prefixed, array $terms, bool $all): void
    {
        if ($columns === [] || $terms === []) {
            return;
        }

        // Wrapped, always: without the outer group these OR clauses would
        // escape into whatever WHERE the caller already had and match the
        // whole table — the classic form of this bug.
        $query->where(function (Builder $outer) use ($columns, $prefixed, $terms, $all): void {
            foreach ($terms as $term) {
                $group = function (Builder $inner) use ($columns, $prefixed, $term): void {
                    foreach ($columns as $column) {
                        $inner->orWhere($column, 'like', self::pattern($column, $prefixed, $term));
                    }
                };

                $all ? $outer->where($group) : $outer->orWhere($group);
            }
        });
    }

    /**
     * `(matches term one) + (matches term two) + …` — how many of the words a
     * row actually has. MySQL yields 1 or 0 for a boolean expression, so the
     * groups simply add up.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $prefixed
     * @param  list<string>  $terms
     * @return array{0: string, 1: list<string>}
     */
    private static function relevance(array $columns, array $prefixed, array $terms): array
    {
        $groups = [];
        $bindings = [];

        foreach ($terms as $term) {
            $tests = [];

            foreach ($columns as $column) {
                $tests[] = $column.' like ?';
                $bindings[] = self::pattern($column, $prefixed, $term);
            }

            $groups[] = '('.implode(' or ', $tests).')';
        }

        return ['('.implode(' + ', $groups).') as relevance', $bindings];
    }

    /**
     * @param  list<string>  $prefixed
     */
    private static function pattern(string $column, array $prefixed, string $term): string
    {
        return in_array($column, $prefixed, true) ? $term.'%' : '%'.$term.'%';
    }
}
