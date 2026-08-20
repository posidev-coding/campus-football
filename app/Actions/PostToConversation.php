<?php

namespace App\Actions;

use App\Exceptions\HandleRequired;
use App\Exceptions\NotGroupMember;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PostingTooFast;
use App\Models\ConversationPost;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * One message into one conversation.
 *
 * Every gate lives HERE — verified email, claimed handle, a topic that is
 * one of the three sanctioned scopes, membership when the scope is a
 * group, a body that survives trimming, and the flood limiter — because
 * this is reachable from a public Livewire method and a hidden composer is
 * presentation, not enforcement. It is the same doctrine {@see MakePick}
 * follows, for the same reason.
 *
 * The scope check is a WHITELIST rather than a morph-map lookup: the map
 * identity-maps User so notifications keep working, which means
 * `getMorphClass()` happily returns a class name for a model that must
 * never carry a conversation. Three scopes is a product decision — a
 * fourth is a conversation with the roadmap, not a new string here.
 *
 * Posts are immutable, so there is no update path and never an upsert:
 * saying it differently means saying it again, in public.
 */
class PostToConversation
{
    public function __construct(private GrantWalletEntry $wallet) {}

    /**
     * The `body` column's own width. Checked here so the 501st character is
     * a refusal the writer can see rather than a silent MySQL truncation
     * that changes what they said.
     */
    public const MAX_LENGTH = 500;

    /**
     * Spelled out in seconds, never `now()->addMinute()->diffInSeconds()` —
     * that is NEGATIVE in Carbon 3, which expires the key the instant it is
     * written and makes the limiter permit everything. It would fail OPEN.
     */
    public const WINDOW = 60;

    public const MAX_PER_WINDOW = 6;

    /** The sanctioned scopes, and the whole list of them. */
    public const SCOPES = ['game', 'team', 'group'];

    /**
     * @throws PickemParticipationGated when the author is unverified
     * @throws HandleRequired when no handle has been claimed
     * @throws NotGroupMember when posting into a group from outside it
     * @throws PostingTooFast when the author is inside the cooldown
     */
    public function handle(User $user, Model $topic, string $body): ConversationPost
    {
        if (! $user->hasVerifiedEmail()) {
            throw new PickemParticipationGated;
        }

        if ($user->handle === null) {
            throw new HandleRequired;
        }

        $scope = $topic->getMorphClass();

        if (! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException("A conversation cannot hang off [{$scope}].");
        }

        /*
         * A group's talk is for the people in it — the one scope with a
         * membership wall, because the group IS the room. Games and teams
         * are public surfaces and their conversations are too.
         */
        if ($topic instanceof Group) {
            $isMember = GroupMember::query()
                ->where('group_id', $topic->id)
                ->where('user_id', $user->id)
                ->exists();

            if (! $isMember) {
                throw new NotGroupMember;
            }
        }

        $body = trim($body);

        if ($body === '') {
            throw new InvalidArgumentException('A post needs something in it.');
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A post is at most '.self::MAX_LENGTH.' characters.');
        }

        $key = "conversation:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_WINDOW)) {
            throw new PostingTooFast(RateLimiter::availableIn($key));
        }

        // Hit only on the way to a real row: a refused post must not spend
        // the author's budget, or a typo costs them their next minute.
        RateLimiter::hit($key, self::WINDOW);

        $post = ConversationPost::query()->create([
            'topic_type' => $scope,
            'topic_id' => $topic->getKey(),
            'user_id' => $user->id,
            'body' => $body,
        ]);

        /*
         * Talking pays, three times a day. The cap is deliberately lower than
         * the limiter allows in a single minute: the limiter stops a flood,
         * this stops FARMING, and the two want different numbers. Paid after
         * the row exists, so an earn can never outlive the post it was for.
         */
        $this->wallet->daily(
            $user,
            GrantWalletEntry::TALK_XP,
            0,
            GrantWalletEntry::REASON_TALK,
            GrantWalletEntry::TALK_DAILY_CAP,
        );

        return $post;
    }
}
