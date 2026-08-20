<?php

use App\Actions\DeleteConversationPost;
use App\Actions\PostToConversation;
use App\Exceptions\CannotModeratePost;
use App\Exceptions\HandleRequired;
use App\Exceptions\NotGroupMember;
use App\Exceptions\PickemParticipationGated;
use App\Exceptions\PostingTooFast;
use App\Livewire\Concerns\ClaimsHandle;
use App\Models\ConversationPost;
use App\Models\Game;
use App\Models\Group;
use App\Models\Team;
use App\Support\Voice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Conversation — one surface, three scopes, mounted the same way on
 * each.
 *
 * The topic arrives as its MORPH ALIAS plus an id rather than a bound
 * model, because the three hosts pass three different classes and a
 * primitive pair hydrates identically for all of them. It is also the pair
 * the table actually stores, so what the component holds is what the query
 * uses — and an id off the wire can only ever reach the three classes
 * `topic()` names.
 *
 * Reading is open to everyone, including guests and the unverified — the
 * gate is on the WRITE and it lives in the Action, never in a route wall.
 * What this component does with the refusals is presentation: it shows the
 * claim form, the verify nudge or the join prompt, next to the box the
 * reader just typed into.
 *
 * Newest LAST, chat-shaped, with the composer under the final post, so the
 * thing you read last is the thing you are answering. The window comes
 * newest-first off the (topic_type, topic_id, id) index and is reversed in
 * PHP; "show older" WIDENS it rather than paging, because a thread that
 * jumps you to page 2 loses the argument you were reading.
 */
new class extends Component
{
    use ClaimsHandle;

    /** One of PostToConversation::SCOPES. */
    public string $topicType;

    public int $topicId;

    public string $body = '';

    public ?string $notice = null;

    /** How much of the thread is on screen; `older()` widens it. */
    public int $shown = self::PAGE;

    private const PAGE = 25;

    public function mount(Model $topic): void
    {
        $this->topicType = $topic->getMorphClass();
        $this->topicId = $topic->getKey();
    }

    /**
     * The raw window, newest first, with ONE extra row fetched purely to
     * answer "is there older?" without a second count over the same index.
     *
     * @return Collection<int, ConversationPost>
     */
    #[Computed]
    public function window(): Collection
    {
        return ConversationPost::query()
            // Lazy loading is off, and the row renders the author's handle
            // with their name as the fallback — so all three load here.
            ->with('user:id,first_name,last_name,handle')
            ->where('topic_type', $this->topicType)
            ->where('topic_id', $this->topicId)
            ->orderByDesc('id')
            ->limit($this->shown + 1)
            ->get();
    }

    /**
     * What the thread actually renders: oldest-first, and never the probe
     * row — taking the newest `shown` BEFORE reversing is what keeps the
     * extra row off the top of the list.
     *
     * @return Collection<int, ConversationPost>
     */
    #[Computed]
    public function posts(): Collection
    {
        return $this->window->take($this->shown)->reverse()->values();
    }

    #[Computed]
    public function hasOlder(): bool
    {
        return $this->window->count() > $this->shown;
    }

    public function older(): void
    {
        $this->shown += self::PAGE;

        $this->refreshThread();
    }

    /**
     * The topic itself, resolved from the alias the morph map already
     * sanctions — never a class name off the wire.
     */
    #[Computed]
    public function topic(): Model
    {
        return match ($this->topicType) {
            'game' => Game::query()->findOrFail($this->topicId),
            'team' => Team::query()->findOrFail($this->topicId),
            'group' => Group::query()->findOrFail($this->topicId),
            default => abort(404),
        };
    }

    public function post(PostToConversation $action): void
    {
        if (! auth()->check()) {
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $this->notice = null;

        try {
            $action->handle(auth()->user(), $this->topic, $this->body);
        } catch (PickemParticipationGated) {
            $this->notice = Voice::line('talk.verify_first');

            return;
        } catch (HandleRequired) {
            // The claim form is already on screen; nothing to say twice.
            return;
        } catch (NotGroupMember) {
            $this->notice = Voice::line('talk.not_member');

            return;
        } catch (PostingTooFast $e) {
            $this->notice = Voice::line('talk.too_fast', ['seconds' => $e->availableIn]);

            return;
        } catch (InvalidArgumentException) {
            // Empty, or past the textarea's own maxlength. The box says it
            // better than a notice would, and nothing was written.
            return;
        }

        $this->body = '';

        // The new post EXTENDS the window rather than pushing the oldest
        // post out from under someone mid-read.
        $this->shown++;

        $this->refreshThread();
    }

    public function deletePost(int $postId, DeleteConversationPost $action): void
    {
        $post = ConversationPost::query()->find($postId);

        if ($post === null || ! auth()->check()) {
            return;
        }

        try {
            $action->handle(auth()->user(), $post);
        } catch (CannotModeratePost) {
            // The button is not drawn for anyone who may not; a race here
            // just re-renders without it.
            return;
        }

        $this->notice = Voice::line('talk.deleted');

        $this->refreshThread();
    }

    public function claim(): void
    {
        $this->notice = Voice::line('talk.claim.done', ['handle' => $this->claimHandle()]);
    }

    /**
     * Whether the viewer may pull each visible post — asked through the
     * Action, so the button and the enforcement read one rule.
     *
     * @return array<int, bool> keyed by post id
     */
    #[Computed]
    public function moderatable(): array
    {
        if (! auth()->check()) {
            return [];
        }

        $action = app(DeleteConversationPost::class);
        $user = auth()->user();

        return $this->posts
            ->mapWithKeys(fn (ConversationPost $post) => [$post->id => $action->mayModerate($user, $post)])
            ->all();
    }

    private function refreshThread(): void
    {
        unset($this->window, $this->posts, $this->hasOlder, $this->moderatable);
    }
}; ?>

<section class="flex flex-col gap-4">
    <div class="flex flex-col gap-0.5">
        {{-- A section heading inside a screen, not the screen's own h1 — the
             label stays plain and the line under it does the talking. --}}
        <flux:heading size="lg">Talk</flux:heading>
        <flux:subheading>{{ Voice::line('talk.subheading.'.$topicType) }}</flux:subheading>
    </div>

    @if ($notice)
        <p class="rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
            {{ $notice }}
        </p>
    @endif

    <div class="flex flex-col gap-3">
        @if ($this->hasOlder)
            <flux:button wire:click="older" size="xs" variant="ghost" class="self-start">
                Show older
            </flux:button>
        @endif

        @forelse ($this->posts as $post)
            {{-- Its own key per row: without one the morph reuses the node,
                 and a deleted post leaves its neighbor's body behind. --}}
            <div wire:key="post-{{ $post->id }}" class="flex items-start gap-3">
                <div class="min-w-0 flex-1">
                    <p class="flex items-baseline gap-2">
                        {{-- min-w-0, or the handle's nowrap width stops the
                             flex item ever clipping and the document scrolls
                             sideways instead. --}}
                        <span class="min-w-0 truncate text-sm font-semibold">
                            {{ $post->user->handle ? '@'.$post->user->handle : $post->user->name }}
                        </span>
                        <span class="shrink-0 text-micro text-zinc-500 dark:text-zinc-400">
                            {{ $post->created_at->diffForHumans(short: true) }}
                        </span>
                    </p>
                    {{-- break-words, because one 500-character word is a legal
                         post and would otherwise widen the page. --}}
                    <p class="text-sm break-words whitespace-pre-line text-zinc-700 dark:text-zinc-300">{{ $post->body }}</p>
                </div>

                @if ($this->moderatable[$post->id] ?? false)
                    <flux:button
                        wire:click="deletePost({{ $post->id }})"
                        wire:confirm="Delete this post? It does not come back."
                        size="xs"
                        variant="ghost"
                        class="shrink-0"
                        aria-label="Delete post"
                    >
                        <flux:icon.trash variant="micro" />
                    </flux:button>
                @endif
            </div>
        @empty
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ Voice::line('talk.empty.'.$topicType) }}
            </p>
        @endforelse
    </div>

    @guest
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            <flux:link href="{{ route('login') }}">{{ Voice::line('talk.guest') }}</flux:link>
        </p>
    @endguest

    @auth
        @if ($this->needsHandle)
            {{-- The same claim moment the pick surface raises, because it is
                 the same claim: the first pick OR the first post. --}}
            <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading>{{ Voice::line('picks.claim.heading') }}</flux:heading>
                <flux:subheading>{{ Voice::line('talk.claim.body') }}</flux:subheading>
                <form wire:submit="claim" class="flex flex-col gap-3">
                    {{-- The format rule stays plain — a joke would eat it. --}}
                    <flux:input
                        wire:model="handle"
                        label="Handle"
                        description="Lowercase letters, numbers and underscores."
                        maxlength="20"
                        autocomplete="off"
                        x-mask:dynamic="$input.toLowerCase().replace(/[^a-z0-9_]/g, '').slice(0, 20)"
                    />
                    <flux:button type="submit" variant="primary" class="self-start">Claim it</flux:button>
                </form>
            </div>
        @else
            {{-- Deferred wire:model on purpose: a live-bound composer sends a
                 round trip per keystroke to decide one button's disabled
                 state, and an empty body is already a no-op in the Action. --}}
            <form wire:submit="post" class="flex flex-col gap-2">
                <flux:textarea
                    wire:model="body"
                    placeholder="Say something"
                    aria-label="Say something"
                    rows="3"
                    maxlength="{{ App\Actions\PostToConversation::MAX_LENGTH }}"
                />
                <div class="flex items-center justify-between gap-3">
                    <p class="min-w-0 text-micro text-zinc-500 dark:text-zinc-400">
                        {{ Voice::line('talk.house_rule') }}
                    </p>
                    <flux:button type="submit" size="sm" variant="primary" class="shrink-0">
                        Post
                    </flux:button>
                </div>
            </form>
        @endif
    @endauth
</section>
