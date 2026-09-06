<?php

namespace App\Support;

use App\Actions\GrantWalletEntry;
use App\Enums\ContentRating;
use App\Enums\LobbyShelf;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Contests\ModeEngine;
use App\Services\Contests\WoodshedMode;
use Illuminate\Support\Str;

/**
 * The closed vocabulary of things the help sheet can answer — the role
 * {@see Stats\StatCatalog} plays for the stat answers, on the surface that
 * explains the app rather than the season.
 *
 * ONE LIST FEEDS BOTH ENDS. `vocabulary()` is what the classifier is shown
 * and `keys()` is the schema enum it must answer from, so the prompt and the
 * resolver cannot drift: a topic added here is a topic the model can name
 * and the app can answer, in the same commit, or neither.
 *
 * THE MODEL NEVER WRITES AN ANSWER. Every body is a `help.{key}` line in
 * {@see Voice}, three registers, written by a person and accurate to the code
 * it describes — and the live numbers in it are read from that code
 * ({@see GrantWalletEntry}'s constants, {@see Cadence}'s labels), never
 * restated. A rebalance moves the answer without anybody editing it.
 *
 * Fills are constants and labels. The two Cadence labels cost one memoized
 * `pickem_settings` read, and only inside a tap — nothing here runs on
 * render, because the sheet renders on every signed-in page.
 */
final class HelpTopics
{
    /**
     * key => the answer's heading, the "take me there" route and params, and
     * the one-line summary the classifier sorts against. The summaries have
     * to SEPARATE the near-neighbors — locking versus the tiebreaker versus
     * the commissioner's slate, the cooler versus a wager — because a
     * twenty-way enum misroutes between siblings before it misroutes to a
     * stranger.
     *
     * @var array<string, array{title: string, route: string, params: array<string, mixed>, summary: string}>
     */
    public const TOPICS = [
        'picks.make' => [
            'title' => 'Making a pick',
            'route' => 'pickem.home',
            'params' => [],
            'summary' => 'how to make a pick: tapping a team on a game card in My Picks or in a group',
        ],
        'picks.lock' => [
            'title' => 'When picks lock',
            'route' => 'pickem.home',
            'params' => [],
            'summary' => "when a pick locks (at that game's own kickoff) and changing a pick before then",
        ],
        'picks.privacy' => [
            'title' => 'Who sees your picks',
            'route' => 'pickem.home',
            'params' => [],
            'summary' => 'whether other people can see your picks before or after kickoff',
        ],
        'picks.lock_wager' => [
            'title' => 'The Lock',
            'route' => 'pickem.how',
            'params' => [],
            'summary' => 'the Lock in Woodshed: staking the featured game for bonus points, and its penalty',
        ],
        'picks.tallboy' => [
            'title' => 'Crushing a Tallboy',
            'route' => 'pickem.how',
            'params' => [],
            'summary' => 'crushing (wagering) a Tallboy on one game: the swing, what it costs, how many per week',
        ],
        'picks.scoring' => [
            'title' => 'How a week is scored',
            'route' => 'pickem.how',
            'params' => [],
            'summary' => 'how points are scored per game in each mode (Shotgun, Triple Option, Woodshed); the perfect week',
        ],
        'picks.tiebreaker' => [
            'title' => 'The tiebreaker',
            'route' => 'pickem.home',
            'params' => [],
            'summary' => 'the tiebreaker question on a slate: what to answer, when it locks, how a tie on points breaks',
        ],
        'picks.settle' => [
            'title' => 'When results are official',
            'route' => 'pickem.history',
            'params' => [],
            'summary' => 'when a week becomes official and pays out; what Preliminary means on a scored week',
        ],
        'groups.create' => [
            'title' => 'Starting a group',
            'route' => 'pickem.create',
            'params' => [],
            'summary' => 'creating a new private group and choosing its mode',
        ],
        'groups.join' => [
            'title' => 'Joining a group',
            'route' => 'pickem.join',
            'params' => [],
            'summary' => 'joining a group with an invite code or an invite link somebody sent',
        ],
        'groups.invite' => [
            'title' => 'Inviting people',
            'route' => 'pickem.home',
            'params' => [],
            'summary' => 'inviting friends into a group you are already in: the code, the link, the share card',
        ],
        'groups.mode' => [
            'title' => 'Changing the mode',
            'route' => 'pickem.home',
            'params' => [],
            'summary' => "changing a group's mode after it was created, who may do it and how often",
        ],
        'groups.slate' => [
            'title' => 'Who picks the games',
            'route' => 'pickem.home',
            'params' => [],
            'summary' => "who builds the weekly slate of games, the commissioner's publish deadline, what happens if nobody builds it",
        ],
        'lobby.rooms' => [
            'title' => 'Public rooms',
            'route' => 'pickem.lobby',
            'params' => [],
            'summary' => 'what a public room or contest in the Lobby is and how to get a seat in one',
        ],
        'lobby.cost' => [
            'title' => 'What a room costs',
            'route' => 'pickem.how',
            'params' => [],
            'summary' => 'what it costs to enter a room; which rooms are free and which charge a Tallboy',
        ],
        'wallet.tallboys' => [
            'title' => 'Tallboys and XP',
            'route' => 'pickem.how',
            'params' => [],
            'summary' => 'what Tallboys and XP are, the cooler, and how you earn or get more of them',
        ],
        'wallet.ranks' => [
            'title' => 'The ranks',
            'route' => 'pickem.home',
            'params' => [],
            'summary' => 'the rank ladder (Walk-On up to Legend) and how XP moves you up it',
        ],
        'wallet.verify' => [
            'title' => 'Verifying your email',
            'route' => 'account',
            'params' => [],
            'summary' => 'why the app asks you to verify your email and what verifying unlocks',
        ],
        'account.rating' => [
            'title' => 'The content rating',
            'route' => 'account',
            'params' => [],
            'summary' => 'the content rating (Mild, Medium, Spicy): turning the trash talk up or down',
        ],
        'account.handle' => [
            'title' => 'Claiming a handle',
            'route' => 'account',
            'params' => [],
            'summary' => 'claiming or changing your handle (@name) and the rules for one',
        ],
        'account.teams' => [
            'title' => 'Your teams',
            'route' => 'account',
            'params' => [],
            'summary' => 'following a team, reordering or unfollowing teams, and how many you may follow',
        ],
        'account.data' => [
            'title' => 'What the app records',
            'route' => 'privacy',
            'params' => [],
            'summary' => 'what the app records about you, what it keeps, how long, and what happens when you delete your account',
        ],
        'account.tours' => [
            'title' => 'The tour',
            'route' => 'home',
            'params' => ['tour' => 1],
            'summary' => 'replaying the guided tour of the app or the tour of Picks',
        ],
        'league.where' => [
            'title' => 'Scores and standings',
            'route' => 'scoreboard',
            'params' => [],
            'summary' => 'where to find scores, rankings, standings, team and player stats in the app',
        ],
    ];

    /**
     * The three questions offered on the empty screen — each carries its
     * topic, so a tap answers with NO model call. Every example must resolve;
     * a suggestion the app then declines teaches that asking does not work.
     *
     * @var list<array{question: string, topic: string}>
     */
    public const EXAMPLES = [
        ['question' => 'When do my picks lock?', 'topic' => 'picks.lock'],
        ['question' => 'How do I join a group?', 'topic' => 'groups.join'],
        ['question' => 'What does a room cost?', 'topic' => 'lobby.cost'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TOPICS);
    }

    /** One line per topic, for the prompt — the same list the schema enumerates. */
    public static function vocabulary(): string
    {
        return implode("\n", array_map(
            fn (string $key, array $topic): string => "- `{$key}` — {$topic['summary']}",
            array_keys(self::TOPICS),
            array_values(self::TOPICS),
        ));
    }

    /** @return list<array{question: string, topic: string}> */
    public static function examples(): array
    {
        return self::EXAMPLES;
    }

    /**
     * The answer for one topic, or null for a key we do not hold — including
     * a key whose copy is unwritten, because a heading over a hole is worse
     * than a decline.
     *
     * @return array{topic: string, title: string, body: string, href: string, cta: string}|null
     */
    public static function answer(string $key, ?User $for): ?array
    {
        $topic = self::TOPICS[$key] ?? null;

        if ($topic === null) {
            return null;
        }

        $body = Voice::line("help.{$key}", self::fills($key), $for);

        if ($body === '') {
            return null;
        }

        [$route, $params] = self::destination($key, $topic, $for);

        return [
            'topic' => $key,
            'title' => $topic['title'],
            'body' => $body,
            'href' => route($route, $params),
            'cta' => $key === 'account.tours' ? 'Start the tour' : 'Take me there',
        ];
    }

    /**
     * The one per-reader door: a reader who has not verified is sent to do
     * it, one who has is sent to Account. An attribute on the loaded user,
     * never a query.
     *
     * @param  array{title: string, route: string, params: array<string, mixed>, summary: string}  $topic
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function destination(string $key, array $topic, ?User $for): array
    {
        if ($key === 'wallet.verify' && $for !== null && ! $for->hasVerifiedEmail()) {
            return ['verification.notice', []];
        }

        return [$topic['route'], $topic['params']];
    }

    /**
     * The live values a body reads, from the code the screens read. A count
     * that has a noun beside it arrives already pluralized, so the copy never
     * has to guess at "1 Tallboys".
     *
     * @return array<string, string|int>
     */
    private static function fills(string $key): array
    {
        return match ($key) {
            'picks.lock_wager' => [
                'bonus' => WoodshedMode::LOCK_BONUS,
                'penalty' => WoodshedMode::LOCK_PENALTY,
            ],
            'picks.tallboy' => ['swing' => ModeEngine::TALLBOY_SWING],
            'picks.settle' => [
                'official' => Cadence::officialLabel(),
                'win_xp' => GrantWalletEntry::PICKEM_WIN_XP,
                'prize' => self::tallboys(GrantWalletEntry::PICKEM_WIN_CREDITS),
            ],
            'groups.slate' => ['deadline' => Cadence::deadlineLabel()],
            'lobby.cost' => ['spotlight' => self::tallboys(LobbyShelf::Spotlight->entryCredits())],
            'wallet.tallboys' => [
                'capacity' => GrantWalletEntry::COOLER_CAPACITY,
                'win_xp' => GrantWalletEntry::PICKEM_WIN_XP,
                'prize' => self::tallboys(GrantWalletEntry::PICKEM_WIN_CREDITS),
                'points_xp' => GrantWalletEntry::PICKEM_POINTS_XP_EACH,
            ],
            'wallet.ranks' => ['rungs' => implode(', ', array_keys(RankLadder::RUNGS))],
            'wallet.verify' => [
                'xp' => GrantWalletEntry::VERIFICATION_XP,
                'reward' => self::tallboys(GrantWalletEntry::VERIFICATION_CREDITS),
            ],
            'account.rating' => [
                'mild' => ContentRating::Pg->label(),
                'medium' => ContentRating::Pg13->label(),
                'spicy' => ContentRating::R->label(),
            ],
            'account.teams' => ['max' => User::MAX_FOLLOWED_TEAMS],
            'account.data' => ['days' => ActivityEvent::KEEP_DAYS],
            default => [],
        };
    }

    private static function tallboys(int $count): string
    {
        return $count.' '.Str::plural('Tallboy', $count);
    }
}
