---
paths:
  - 'app/Actions/PostToConversation.php,app/Actions/DeleteConversationPost.php,resources/views/livewire/conversation.blade.php'
---

# Livewire

## The Conversation: whitelist the three scopes, and never edit a post
A conversation IS its (topic_type, topic_id) pair — no parent table. The scope check in PostToConversation is a WHITELIST (game/team/group), NOT a morph-map lookup: User is identity-mapped in AppServiceProvider so notifications keep resolving, which means getMorphClass() returns a perfectly valid class name for a model that must never carry a conversation. Only the list catches it.

conversation_posts has no updated_at on purpose, so moderation DELETES and never rewrites — an editable post lets a quote be made to lie about what was said. DeleteConversationPost::mayModerate() is the one rule both the button and the enforcement ask; a group commissioner may pull posts in THEIR group only, admins anywhere, authors their own.

Reading is open to everyone including guests; every write gate (verified email, claimed handle, group membership, the 60-second limiter) lives in the Action because the Livewire method is public and a hidden composer is presentation. The limiter is hit only on the way to a real row, so a refused post never spends the author's budget.

Placement is a constraint, not taste: the component mounts at the FOOT of Game and Team, never as a tab — x-plate throws above three tabs and the team nav is a measured 358px row with 54px spare that does not scroll. The CLUBHOUSE foot lost its embed on 2026-08-29 (the group screen is the pick surface, and a thread under it read as distraction), and on 2026-08-30 the group scope got its DOOR instead: a dedicated screen at `/groups/{group}/talk` (`group-talk`, members-only both kinds), reached from the hero's talk button, the Standings-foot link-row, and the entry celebration. The pick surface stays chat-free forever — ConversationTest still pins that `group.blade.php` contains no `<livewire:conversation`, and the embed must never drift back in; the dedicated screen is the one sanctioned group render site. (SUPERSEDED 2026-09-01 from "a dedicated screen" onward — see "The clubhouse hosts the group thread on its Talk tab" below: the thread is the clubhouse's last gutter tab, `group-talk` is deleted, `/groups/{g}/talk` 301s to `?view=talk`, and the chat-free pin moved to `partials/pick-slate` and the slate view.)

## The clubhouse hosts the group thread on its Talk tab; the pick SURFACE stays chat-free
Since 2026-09-01 the group thread is the clubhouse's LAST gutter tab (`?view=talk`, members only, both kinds — a non-member's address folds to the slate and the strip has no stop for them), mounted non-lazy inside the exclusive `$view === 'talk'` branch of group.blade.php. The dedicated `group-talk` screen is deleted and `/groups/{g}/talk` (`pickem.talk`) is a 301 to `?view=talk` on the kind's own home, so every link in a text thread keeps working. The pick SURFACE stays chat-free: `partials/pick-slate` never mounts a conversation and the slate view renders none — ConversationTest pins the partial's source and the slate/talk renders behaviorally. The entry celebration's "Talk it over" link is the tab. This supersedes the 2026-08-30 "dedicated screen" sentence in the older rule above; that text is marked in place, never rewritten.
