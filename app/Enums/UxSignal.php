<?php

namespace App\Enums;

/**
 * The product funnel's whole vocabulary — eight named signals, and nothing
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
 */
enum UxSignal: string
{
    /**
     * A guest OPENED the wizard — pressed the front door, deliberately. Not
     * "the overlay mounted": it mounts on every Home render, and counting
     * that made this a traffic number wearing a funnel step's name.
     */
    case OnboardingOpened = 'onboarding_opened';

    /** An account was created through the wizard. */
    case OnboardingRegistered = 'onboarding_registered';

    /** The favorite-team moment was completed — the arrival. */
    case OnboardingTeamPicked = 'onboarding_team_picked';

    /** The favorite-team moment was skipped. */
    case OnboardingSkipped = 'onboarding_skipped';

    /** The guided tour ended, whether by finishing it or closing it. */
    case TourDismissed = 'tour_dismissed';

    /** Somebody opened a /join/{CODE} link. The top of the acquisition funnel. */
    case InviteOpened = 'invite_opened';

    /** A member loaded a published slate they were eligible to pick. */
    case SlateEntered = 'slate_entered';

    /** A member's FIRST pick on a slate — the moment they are really playing. */
    case FirstPickMade = 'first_pick_made';
}
