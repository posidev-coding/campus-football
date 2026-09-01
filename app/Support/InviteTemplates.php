<?php

namespace App\Support;

use App\Enums\ContestMode;
use App\Models\Group;

/**
 * The message a commissioner actually sends, per channel, ready to paste.
 *
 * The gap this closes: the app could hand you a LINK and nothing else, so
 * every invite was somebody composing a pitch from memory at the moment
 * they least wanted to — and half of them shipped a bare URL with no
 * mention of the game, the deadline or what the thing even is.
 *
 * DATA, not Voice — the `RoomNames` posture. Voice holds register-varied
 * product copy and `PickemVoiceTest` sweeps it for all three registers; a
 * five-paragraph email is not that, and forcing one into pg/pg13/r would
 * produce three maintained essays where one is already too many. The copy
 * AROUND these blocks (the panel's heading, its hint) is Voice's, and is
 * written in all three.
 *
 * Two facts are never restated here, they are READ:
 *
 * - the rules come from `ContestMode::ruleLines()`, which its own docblock
 *   names as the one source "the lobby's explainer, the mode doors, the
 *   join landing and the docs all read, so the game is never described two
 *   ways". An invite that spelled the scoring out by hand would be a
 *   fourth place for it to drift, and the one people read FIRST.
 * - the deadline comes from `Cadence::deadlineLabel()`, which is
 *   admin-configurable. It moved from Saturday noon (the 2016 founders'
 *   league) to Thursday noon ET on 2026-08-20; a hardcoded day in an
 *   invitation is a support conversation on day one.
 *
 * These are scaffolds, not a voice. They carry the facts and a working
 * link; the sender's own reason for sending it is the sender's, and the
 * panel says so rather than putting words in a person's mouth.
 */
class InviteTemplates
{
    /**
     * Every channel's message for one group, in the order a commissioner
     * is likeliest to want them.
     *
     * `$games` is the CONTEST's own slate size, never the mode's default —
     * a downsized Shotgun room deals eight, and an invitation that
     * promises ten is the group lying about the game it is selling.
     *
     * @return list<array{key: string, label: string, hint: string, subject: string|null, body: string}>
     */
    public static function for(Group $group, ContestMode $mode, string $url, ?int $games = null): array
    {
        $rules = $mode->ruleLines($games);
        $deadline = Cadence::deadlineLabel();
        $bullets = collect($rules)->map(fn (string $line): string => '• '.$line)->implode("\n");

        return [
            [
                'key' => 'sms',
                'label' => 'Text message',
                'hint' => 'Short enough to send as one message.',
                'subject' => null,
                'body' => implode(' ', [
                    "Starting a college football pick'em group — {$group->name}.",
                    "We're playing {$mode->label()}: {$rules[0]}",
                    "Picks are due {$deadline} every week.",
                    "Grab a seat: {$url}",
                ]),
            ],
            [
                'key' => 'slack',
                'label' => 'Slack post',
                // The whole reason the QR exists is this sentence.
                'hint' => 'Read on a desktop, joined on a phone — post the QR with it.',
                'subject' => null,
                'body' => <<<TXT
                *{$group->name}* — a college football pick'em group, if you want in.

                Every week you pick games against the spread. It scores itself, it ranks everybody, and it talks back.

                *The game: {$mode->label()}*
                {$bullets}

                Picks are due *{$deadline}* each week. Miss a week and you just score zero for it — nothing else happens.

                Join here: {$url}
                (Or open the app and enter code *{$group->code}*.)

                It's built for a phone — scan the code below and it'll take you straight in.
                TXT,
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'hint' => 'Send it from your own inbox, not from the app.',
                'subject' => "{$group->name}: your seat is open",
                'body' => <<<TXT
                {$group->name} is running on Campus Football this season, and there's a seat with your name on it.

                HOW IT WORKS

                Every week there's a card of games. You pick each one against the spread before the deadline, and the app scores it, ranks everybody, and settles the week on its own. No spreadsheet, no one chasing you for picks.

                THE GAME: {$mode->label()}

                {$bullets}

                THE CLOCK

                Picks are due {$deadline} every week. If you miss a week you score zero for it and you're still in — nothing drops you.

                TAKING YOUR SEAT

                {$url}

                That link is the whole thing: it'll walk you through making an account and drop you straight into the group. If you'd rather do it by hand, make an account and enter the code {$group->code}.

                One housekeeping note: you'll get an email asking you to confirm your address. You have to click it before you can make picks — that's the one step people skip.
                TXT,
            ],
        ];
    }
}
