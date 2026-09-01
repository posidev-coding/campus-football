<?php

namespace App\Enums;

/**
 * The product funnel's whole vocabulary — nine named signals, and nothing
 * else may be counted.
 *
 * BOUNDED ON PURPOSE. This is the one telemetry surface no off-the-shelf APM
 * can produce, because the events are specific to this product; the price of
 * that is that a free-text event name would let the funnel grow a hundred
 * one-off counters nobody ever reads, and would put user-chosen strings into
 * a store meant for arithmetic. An enum makes the vocabulary a code review.
 *
 * Counted in Redis and rolled up nightly — see App\Actions\RecordUxEvent.
 * Aggregate only: there is deliberately no user id, no session and no free
 * text in anything this pipeline COUNTS, so the snapshot the advisor reads can
 * carry the funnel without carrying anybody's identity. The lone exception is
 * RecordUxEvent's once-a-day dedupe key, which is a TTL'd Redis set member,
 * is never counted and never persisted — its docblock says so at length.
 *
 * "Slate abandoned with zero picks" is deliberately NOT a case. It is
 * SlateEntered minus FirstPickMade, and a third counter for a difference is a
 * third counter that can disagree with the other two.
 *
 * OnboardingCredentialsReached is the ninth, added 2026-08-31 against a week
 * that read 225 opened, 5 registered and nothing at all in between: everyone
 * who registers finishes (5/5/5 through the team pick and the tour), so the
 * whole loss sat inside three wizard steps the funnel could not tell apart.
 * It earns a case on the rule this docblock already states, not around it —
 * it is a THING THAT HAPPENED at a boundary, not a DIFFERENCE of two things
 * that happened, so there is no second arithmetic path to disagree with. And
 * it is the one boundary worth a counter, because it splits the two halves
 * that call for opposite fixes: "left before we asked them for anything" is a
 * question about the name and rating panes, "left at the email and password
 * form" is a question about the form. One case answers it; a case per step
 * would be three counters for a bar chart nobody reads.
 */
enum UxSignal: string
{
    /**
     * A guest OPENED the wizard — pressed the front door, deliberately. Not
     * "the overlay mounted": it mounts on every Home render, and counting
     * that made this a traffic number wearing a funnel step's name.
     */
    case OnboardingOpened = 'onboarding_opened';

    /**
     * A guest REACHED the credentials pane — they answered their name and
     * their register, and the next thing asked of them is an email and a
     * password. Counted at the step boundary in the wizard's next(), guests
     * only, once per browser per day on the same session hash
     * OnboardingOpened uses: backing up to fix a name and coming forward
     * again is the same arrival, not a second one.
     */
    case OnboardingCredentialsReached = 'onboarding_credentials_reached';

    /** An account was created through the wizard. */
    case OnboardingRegistered = 'onboarding_registered';

    /** The favorite-team moment was completed — the arrival. */
    case OnboardingTeamPicked = 'onboarding_team_picked';

    /** The favorite-team moment was skipped. */
    case OnboardingSkipped = 'onboarding_skipped';

    /** The guided tour ended, whether by finishing it or closing it. */
    case TourDismissed = 'tour_dismissed';

    /**
     * The PICKS walk ended, whether finished or closed. Its own signal
     * rather than a second emitter of TourDismissed: a signal counted from
     * two places stops measuring what it is named after, and these two
     * walks answer different questions about the same reader.
     */
    case PicksTourDismissed = 'picks_tour_dismissed';

    /** Somebody opened a /join/{CODE} link. The top of the acquisition funnel. */
    case InviteOpened = 'invite_opened';

    /** A member loaded a published slate they were eligible to pick. */
    case SlateEntered = 'slate_entered';

    /** A member's FIRST pick on a slate — the moment they are really playing. */
    case FirstPickMade = 'first_pick_made';
}
