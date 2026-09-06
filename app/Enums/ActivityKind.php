<?php

namespace App\Enums;

/**
 * Everything the clickstream may record — a page view, and five moments with
 * no other home.
 *
 * BOUNDED ON PURPOSE, for the reason App\Enums\UxSignal is: free-text event
 * names grow a hundred one-off counters nobody reads, and put user-chosen
 * strings into a store meant for arithmetic. An enum makes the vocabulary a
 * code review.
 *
 * The admission rule is narrower than UxSignal's, because there is a second
 * store now. A case earns its place only if the thing that happened has NO
 * TRUTH TABLE: a pick is `picks`, a post is `conversation_posts`, a join is
 * `group_members`, a follow is `team_follows`, and per-user adoption of each
 * is DERIVED by joining those tables into `user_days` at rollup time. A row
 * here for any of them would be a second counter that can disagree with the
 * first, and a stream entry can be trimmed under load where a truth row
 * cannot. Invite-opened is likewise the page view of the join route, not an
 * action — an action row there would be a second emitter standing beside
 * UxSignal::InviteOpened.
 *
 * So what is left is: a screen somebody read, a search they ran, a question
 * they asked, a notification setting they moved, and a share they tapped.
 * Nothing else has been asked of the app and left no trace.
 *
 * The emitters land in phase 2 (docs/plans/analytics.md); this enum is the
 * vocabulary they will be held to, one file per case.
 */
enum ActivityKind: string
{
    /**
     * A rendered screen. Emitted by the RecordPageView middleware and by
     * nothing else — a Livewire mount hook would count three or four per
     * screen, because a wire:navigate hop re-mounts every layout island,
     * while the GET behind it is exactly one.
     */
    case PageView = 'page_view';

    /**
     * A search was actually run — once per search session, when the query
     * first crosses the minimum length, never once per debounce tick. A
     * counter that moves on every keystroke measures typing.
     */
    case Searched = 'searched';

    /** A stat question, counted after the answer resolves. Facet: why it declined. */
    case StatAsked = 'stat_asked';

    /** A help question. Facet: answered or unanswered. */
    case HelpAsked = 'help_asked';

    /** A notification or newsletter opt-in moved. Facet: which one, and which way. */
    case NotificationToggled = 'notification_toggled';

    /**
     * A share that reached the server. A pure-Alpine copy button records
     * nothing and gets no beacon of its own: a sensor that needs a new
     * network round trip to observe a local action costs the reader more
     * than the number is worth.
     */
    case Shared = 'shared';

    /** Everything except a page view. The `actions` count in `user_days`. */
    public function isAction(): bool
    {
        return $this !== self::PageView;
    }
}
