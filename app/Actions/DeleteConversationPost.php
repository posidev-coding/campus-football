<?php

namespace App\Actions;

use App\Exceptions\CannotModeratePost;
use App\Models\ConversationPost;
use App\Models\GroupMember;
use App\Models\User;

/**
 * Moderation removes a post; it never rewrites one.
 *
 * The column set makes this the only shape available — `conversation_posts`
 * has no `updated_at` because an editable post lets a quote be made to lie
 * about what was said. So a regretted line is deleted whole, and the thread
 * loses it rather than quietly changing it.
 *
 * Three people may pull a post: its author, the commissioner of the group
 * it was posted in, and an app admin. A game or team conversation has no
 * commissioner, so outside a group it is the author or an admin — the
 * league-wide surfaces are moderated by the house.
 *
 * A soft delete would keep the row for an audit nobody has asked for and
 * leave every reader query carrying a `whereNull` forever; the delete is
 * real.
 */
class DeleteConversationPost
{
    /**
     * @throws CannotModeratePost when the actor is none of the three
     */
    public function handle(User $user, ConversationPost $post): void
    {
        if (! $this->mayModerate($user, $post)) {
            throw new CannotModeratePost;
        }

        $post->delete();
    }

    /**
     * The same question the screen asks to decide whether to draw the
     * button, so the affordance and the enforcement can never disagree.
     */
    public function mayModerate(User $user, ConversationPost $post): bool
    {
        if ($post->user_id === $user->id || $user->isAdmin()) {
            return true;
        }

        if ($post->topic_type !== 'group') {
            return false;
        }

        return GroupMember::query()
            ->where('group_id', $post->topic_id)
            ->where('user_id', $user->id)
            ->where('role', GroupMember::COMMISSIONER)
            ->exists();
    }
}
