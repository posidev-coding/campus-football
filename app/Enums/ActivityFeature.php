<?php

namespace App\Enums;

/**
 * What a person actually DID on a league day, as a bitmask — the
 * `user_days.features` column, and the whole of the adoption question.
 *
 * Most of these bits are read from the TRUTH TABLES at rollup time, not from
 * the clickstream: `Picked` is a `picks` row, `Talked` is a
 * `conversation_posts` row, `Joined` is `group_members`, `Followed` is
 * `team_follows`, `Invited` is `group_invites`. That is deliberate and it is
 * the reason the pipeline needs no second emitter for any of them — the fact
 * already exists, durably, and a clickstream entry for it could be trimmed
 * under load while the truth row could not.
 *
 * The bits that CANNOT come from a truth table are the ones the clickstream
 * exists for: reading the talk rather than posting to it, opening the Lobby,
 * looking at stats, running a search, asking a question, and reading anything
 * at all from an installed app. Nothing in the database knows those happened.
 *
 * Eleven bits in an unsignedSmallInteger, which holds sixteen. A twelfth is
 * fine; a seventeenth is a column change, and the day that comes the answer
 * is a wider integer rather than a second mask.
 */
enum ActivityFeature: int
{
    /** Made or changed a pick — `picks.updated_at` inside the day. */
    case Picked = 1;

    /** Posted to a conversation. */
    case Talked = 2;

    /**
     * READ the talk — the clubhouse `talk` facet. Its own bit beside
     * `Talked` because a room where everybody reads and nobody posts is a
     * different room from one where nobody looks, and the two bits are the
     * only way to tell them apart.
     */
    case ReadTalk = 4;

    /** Followed a team. */
    case Followed = 8;

    /** Joined a group. */
    case Joined = 16;

    /** Opened the Lobby. */
    case Lobby = 32;

    /** Opened a stats screen. */
    case Stats = 64;

    /** Ran a search. */
    case Searched = 128;

    /** Asked a stat or help question. */
    case Asked = 256;

    /** Sent an invite. */
    case Invited = 512;

    /** Read at least one screen from the installed app. */
    case Installed = 1024;

    /** Is this feature's bit set in a stored mask? */
    public function in(int $mask): bool
    {
        return ($mask & $this->value) !== 0;
    }
}
