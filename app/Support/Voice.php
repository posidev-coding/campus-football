<?php

namespace App\Support;

use App\Enums\ContentRating;
use App\Models\User;

/**
 * Copy that changes register with the reader.
 *
 * One resolver rather than `match ($rating)` scattered through Blade: three
 * levels across a growing pile of strings does not survive inline ternaries in
 * views, and a single map is also the only place you can see all three variants
 * of a line side by side — which is how you catch PG being written as a
 * punishment.
 *
 * Resolution falls DOWN the ladder, never up. `ContentRating::includes()`
 * already encodes that an R reader may be shown PG copy while a PG reader must
 * never see PG-13, so a line that only defines `pg` is safe to add and a line
 * that only defines `r` simply never reaches the people who did not ask for it.
 *
 * What belongs here: subtext, empty states, limits, confirmations — anywhere
 * the app is talking TO somebody. What does not: format rules and field labels,
 * where a joke between the reader and the instruction is just friction, and
 * anything on Scores or League, which report facts rather than address a person.
 */
class Voice
{
    /**
     * @var array<string, array<string, string>>
     */
    private const LINES = [
        'teams.subheading' => [
            'pg' => 'Pin your favorite. Their news leads your home page.',
            'pg13' => "Pin your favorite. They'll headline your home page — good week or bad.",
            'r' => "Pin your favorite. You'll hear about them either way.",
        ],

        'teams.empty' => [
            'pg' => 'No teams yet. Search above to add your first.',
            'pg13' => 'Nobody yet. Pick a team — bandwagons welcome.',
            'r' => "Empty. Pick somebody, even if you'll regret it by November.",
        ],

        'teams.no_matches' => [
            'pg' => 'No FBS team matches ":query".',
            'pg13' => 'No FBS team called ":query". Check the spelling.',
            'r' => 'Nobody in FBS answers to ":query".',
        ],

        'teams.at_limit' => [
            'pg' => 'All :max spots used — unfollow one to add another',
            'pg13' => "Roster's full at :max. Cut somebody first.",
            'r' => "Bench is full at :max. Somebody's getting cut.",
        ],

        'follow.limit' => [
            'pg' => 'You can follow up to :max teams. Unfollow one to make room.',
            'pg13' => "That's :max teams, which is your limit. Drop one to add another.",
            'r' => "You're at :max. Something's gotta give.",
        ],

        'home.follow_prompt' => [
            'pg' => 'Follow a team and their games, news and trends lead this page.',
            'pg13' => 'Follow a team and this page starts working for you — games, news and trends, front and center.',
            'r' => 'This page is better with a team on it. Pick yours and their games, news and trends take over.',
        ],

        'home.first_team' => [
            'pg' => 'Pick your team and this page fills in — records, trends, next games.',
            'pg13' => 'Pick your team. Records, trends, next games — all of it lands right here.',
            'r' => 'Pick your team. Records, trends, and every upcoming disaster — right here.',
        ],

        'home.another_team' => [
            'pg' => 'Room for :remaining more. Add a rival, or a team you just like watching.',
            'pg13' => 'Room for :remaining more. Add a rival — someone has to lose.',
            'r' => 'Room for :remaining more. Add a rival you enjoy watching suffer.',
        ],

        /*
         * The onboarding card speaks to a GUEST as often as a signed-in user,
         * and a guest has no content rating — `line()` falls back to PG-13,
         * which is the right register for a first impression anyway.
         */
        'onboarding.heading' => [
            'pg' => 'Make this page yours',
            'pg13' => 'Pick a side',
            'r' => 'Pick a side. Any side.',
        ],

        'onboarding.body' => [
            'pg' => 'Follow up to five teams. Records, trends, next games at a glance — all on one screen.',
            'pg13' => 'Follow up to five teams. Records, trends, next games at a glance — and plenty to yell about.',
            'r' => 'Follow up to five teams. Records, trends, next games at a glance, and a season of grievances.',
        ],

        'onboarding.dismissed' => [
            'pg' => 'No problem — you can add teams any time from your account.',
            'pg13' => "Fine. It'll be in your account when you change your mind.",
            'r' => "Suit yourself. It's in your account when you come crawling back.",
        ],

        'onboarding.name' => [
            'pg' => 'What should we call you?',
            'pg13' => 'Easy one first — what do we call you?',
            'r' => 'Easy one first — what do we call you?',
        ],

        'onboarding.rating' => [
            'pg' => 'How much grief should this app give you?',
            'pg13' => 'How much grief can you take?',
            'r' => 'How much grief can you take? Be honest.',
        ],

        'onboarding.credentials' => [
            'pg' => 'Last step — somewhere to keep all this.',
            'pg13' => 'Last one. Somewhere to keep your picks and your records.',
            'r' => 'Last one. Somewhere to file your terrible opinions.',
        ],

        'onboarding.done' => [
            'pg' => "You're set. Your page is waiting.",
            'pg13' => "You're set. Go see what they've done now.",
            'r' => "You're set. Go see what they've done now.",
        ],

        'onboarding.picker' => [
            'pg' => 'Search for a team to follow. You can add up to five.',
            'pg13' => 'Search and tap. Up to five — choose your allegiances carefully.',
            'r' => 'Search and tap. Five slots. Choose your allegiances carefully.',
        ],

        'home.pickem' => [
            'pg' => "Groups, weekly picks and bragging rights with your friends. It's on the way.",
            'pg13' => "Groups, weekly slates, and a season-long paper trail of everyone's bad calls. It's coming.",
            'r' => "Groups, weekly slates, and receipts on every terrible pick your friends swear they never made. It's coming.",
        ],

        'search.empty' => [
            'pg' => 'No matches for ":query". Try the start of a name — "Geo" finds Georgia.',
            'pg13' => 'Swing and a miss on ":query". Try the start of a name — "Geo" finds Georgia.',
            'r' => '":query"? Never heard of them. Try the start of a name — "Geo" finds Georgia.',
        ],

        'profile.subheading' => [
            'pg' => 'Your handle is how everyone else sees you.',
            'pg13' => 'Your handle is what everyone else will be yelling.',
            'r' => "Your handle is what you'll be called. Choose accordingly.",
        ],

        'profile.rating_description' => [
            'pg' => "We'll have opinions about your picks, your team and your record — never about you. This sets how many.",
            'pg13' => 'We roast your picks, your team and your record — never you. This sets how hard.',
            'r' => "We roast your picks, your team and your record — never you. This sets how hard, and you've picked hard.",
        ],
    ];

    /**
     * A line at the reader's level, or the closest one below it.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function line(string $key, array $replace = [], ?User $for = null): string
    {
        $variants = self::LINES[$key] ?? [];

        if ($variants === []) {
            return '';
        }

        $rating = ($for ?? auth()->user())?->content_rating ?? ContentRating::Pg13;

        // `includes()` runs mildest-first, so the reader's own level is last —
        // walk back from there and take the first line that exists.
        foreach (array_reverse($rating->includes()) as $level) {
            if (isset($variants[$level->value])) {
                return self::fill($variants[$level->value], $replace);
            }
        }

        return '';
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function fill(string $line, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $line = str_replace(':'.$key, (string) $value, $line);
        }

        return $line;
    }
}
