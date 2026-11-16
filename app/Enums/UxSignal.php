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
     * A guest OPENED a signup door — pressed the wizard's front door, or
     * loaded the /register form — once per browser per day, software
     * excluded. Not "the overlay mounted": it mounts on every Home render,
     * and counting that made this a traffic number wearing a funnel step's
     * name. Both doors, through App\Livewire\Concerns\CountsSignupFunnel,
     * so it counts the population `onboarding_registered` does (CFB-110).
     */
    case OnboardingOpened = 'onboarding_opened';

    /**
     * A guest REACHED an email and password form — the wizard's last pane,
     * at the step boundary in next(), or the /register form, which opens on
     * it. Guests only, once per browser per day on the same session hash
     * OnboardingOpened uses: backing up to fix a name and coming forward
     * again is the same arrival, not a second one. Every door that counts
     * this counted OnboardingOpened first, and nobody registers without
     * reaching it, so opened >= credentials_reached >= registered.
     */
    case OnboardingCredentialsReached = 'onboarding_credentials_reached';

    /**
     * An account was created, through ANY door — counted on the Registered
     * event by App\Listeners\CountRegistration, never by a screen.
     */
    case OnboardingRegistered = 'onboarding_registered';

    /**
     * A new account completed the favorite-team moment by picking a team —
     * its first pass only, so a member who skipped and adds a team later is
     * not counted again.
     */
    case OnboardingTeamPicked = 'onboarding_team_picked';

    /**
     * A new account skipped the favorite-team moment — its first pass only.
     * With OnboardingTeamPicked, the two ways out of one moment: together
     * they count the accounts that finished onboarding.
     */
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

    /**
     * A member loaded a published slate they were eligible to pick and had
     * NOT yet entered. The "had not yet" is the contract: this is the
     * denominator FirstPickMade is read against, and that one fires once per
     * (user, slate) for all time, so counting every reopen put a per-day
     * denominator over a per-lifetime numerator and made the reported rate
     * fall as engagement rose. Both sides count the same population now.
     *
     * Still a floor rather than an exact rate: somebody who opens on three
     * days and never picks counts three times, because closing that gap
     * needs a durable per-user marker and this pipeline has none by rule.
     */
    case SlateEntered = 'slate_entered';

    /** A member's FIRST pick on a slate — the moment they are really playing. */
    case FirstPickMade = 'first_pick_made';
}
