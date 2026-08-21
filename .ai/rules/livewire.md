---
paths:
  - 'app/Actions/PostToConversation.php,app/Actions/DeleteConversationPost.php,resources/views/livewire/conversation.blade.php'
---

# Livewire

## The Conversation: whitelist the three scopes, and never edit a post
A conversation IS its (topic_type, topic_id) pair — no parent table. The scope check in PostToConversation is a WHITELIST (game/team/group), NOT a morph-map lookup: User is identity-mapped in AppServiceProvider so notifications keep resolving, which means getMorphClass() returns a perfectly valid class name for a model that must never carry a conversation. Only the list catches it.

conversation_posts has no updated_at on purpose, so moderation DELETES and never rewrites — an editable post lets a quote be made to lie about what was said. DeleteConversationPost::mayModerate() is the one rule both the button and the enforcement ask; a group commissioner may pull posts in THEIR group only, admins anywhere, authors their own.

Reading is open to everyone including guests; every write gate (verified email, claimed handle, group membership, the 60-second limiter) lives in the Action because the Livewire method is public and a hidden composer is presentation. The limiter is hit only on the way to a real row, so a refused post never spends the author's budget.

Placement is a constraint, not taste: the component mounts at the FOOT of Game, Team and Group, never as a tab. x-plate throws above three tabs (Group has three) and the team nav is a measured 358px row with 54px spare that does not scroll.
