<?php

namespace App\Support;

use App\Models\Team;

/**
 * Bandwagon State — the team you follow when you follow nobody.
 *
 * A signed-in user with zero follows used to get a Home with no swiper, no
 * glance anchor, and therefore no guided tour: skipping the picker silently
 * cost them the walkthrough. This fills the slot with a deliberately absurd
 * placeholder so the tour has a card to point at and the empty state sells
 * picking a real team instead of apologizing for itself.
 *
 * One identity across every content rating. The name feeds the swiper dots'
 * aria-labels and the news panel's subheading, which are chrome rather than
 * copy — the register-varying humor lives in the Voice lines the card and
 * news panel render (`placeholder.body`, `placeholder.news`). And the
 * numbers are impossible on purpose: no season reaches 99 losses and no
 * conference seats 137 FBS teams, so the record can never be mistaken for a
 * fact — this app has been burned three times by data that looked real.
 *
 * ZERO queries is a hard requirement, not a preference. Home asserts the
 * same query count for one followed team as for five, and the placeholder
 * path must not bend that: `team()` is a `Team::make()` — never `create()`,
 * which would put a joke team in the database AND index it into search —
 * and `glance()` carries no id any set-based query would match.
 */
class PlaceholderTeam
{
    /**
     * 15 characters — inside Team::MAX_PLACE_NAME_LENGTH, so placeName()
     * prints it verbatim instead of falling back to the short name.
     */
    public const LOCATION = 'Bandwagon State';

    /** ESPN's `name` column is the mascot; `nickname` is the location alias. */
    public const MASCOT = 'Frontrunners';

    /** No season is 99 games long. That is the point. */
    public const RECORD = '0-99 (0-99)';

    /** FBS seats 136. Dead last, plus one, in a conference that does not exist. */
    public const STANDING = '137th in the Couch Conference';

    /**
     * The bandwagon's home field, for surfaces that name a venue (the signup
     * splash's road-trip line). Real teams get theirs inferred by TeamVenue;
     * this team's stadium is wherever the winning is.
     */
    public const VENUE = "Wherever's Winning";

    /**
     * The non-persisted model the card renders.
     *
     * `id => 0` is a sentinel no real row uses — it keeps wire:key stable and
     * can never collide with an ESPN team id. No colors, so `palette()`
     * returns null and the card stays neutral; no logo, so x-team-logo's
     * gray-disc fallback reads as the blank it is.
     */
    public static function team(): Team
    {
        return Team::make([
            'id' => 0,
            'slug' => 'bandwagon-state',
            'location' => self::LOCATION,
            'name' => self::MASCOT,
            'display_name' => self::LOCATION.' '.self::MASCOT,
            'short_display_name' => self::LOCATION,
            'abbreviation' => 'BAND',
        ]);
    }

    /**
     * The glance-shaped array Home's swiper consumes.
     *
     * Every key the real pipeline produces, so nothing downstream needs a
     * null check it does not already have; `placeholder => true` is what the
     * swiper branches on.
     *
     * @return array{team: Team, rank: null, record: null, conference: null, position: null, live: null, next: null, last: null, placeholder: true}
     */
    public static function glance(): array
    {
        return [
            'team' => self::team(),
            'rank' => null,
            'record' => null,
            'conference' => null,
            'position' => null,
            'live' => null,
            'next' => null,
            'last' => null,
            'placeholder' => true,
        ];
    }
}
