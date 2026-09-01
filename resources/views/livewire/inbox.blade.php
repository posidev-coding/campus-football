<?php

use App\Support\Voice;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * THE INBOX — everything the app has told this reader, kept.
 *
 * Push is dismissible and mail is refusable, so until now a results
 * announcement could reach somebody and leave no trace they could go back
 * to. The `database` channel costs no budget, no consent and no provider,
 * and is the only one that reaches every reader — which at launch, with
 * zero push subscriptions, is the difference between the weekly loop
 * landing and not.
 *
 * Rows carry STRUCTURED data, never rendered copy: a Voice key and its
 * replacements, resolved here against the CURRENT reader. Freezing the
 * sentence at send time would pin the register, so somebody who later moved
 * to PG would keep seeing the PG-13 line in their own inbox.
 *
 * Latest fifty, no pagination. A reader who has more than fifty unread
 * notifications has a different problem, and the answer to it is a digest
 * rather than a second page.
 */
new class extends Component
{
    /** The newest first. Fifty is the whole screen; see the class note. */
    private const LIMIT = 50;

    #[Computed]
    public function rows()
    {
        return auth()->user()
            ->notifications()
            ->latest()
            ->limit(self::LIMIT)
            ->get();
    }

    #[Computed]
    public function unread(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    /**
     * Open one: mark it read, then go where it points.
     *
     * A Livewire action rather than a plain anchor because the tap has to do
     * both, and an anchor that also fires a request races its own navigation.
     */
    public function open(string $id)
    {
        $row = auth()->user()->notifications()->whereKey($id)->first();

        if ($row === null) {
            return;
        }

        $row->markAsRead();

        $url = $row->data['url'] ?? null;

        unset($this->rows, $this->unread);

        return $url === null ? null : $this->redirect($url, navigate: true);
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);

        unset($this->rows, $this->unread);
    }
}; ?>

<div class="flex flex-col gap-4 md:mx-auto md:w-full md:max-w-3xl">
    {{-- The section strip names this place — the h1 stays for screen
         readers only, the house rule. --}}
    <h1 class="sr-only">Notifications</h1>

    @if ($this->rows->isNotEmpty())
        <div class="flex items-baseline justify-between gap-3">
            <flux:subheading>
                @if ($this->unread > 0)
                    {{ $this->unread }} unread
                @else
                    All caught up
                @endif
            </flux:subheading>

            @if ($this->unread > 0)
                <button
                    type="button"
                    wire:click="markAllRead"
                    wire:loading.attr="disabled"
                    wire:target="markAllRead"
                    class="text-micro shrink-0 font-medium text-blue-600 hover:underline disabled:opacity-50 dark:text-blue-400"
                >
                    Mark all read
                </button>
            @endif
        </div>

        <div class="flex flex-col gap-2">
            @foreach ($this->rows as $row)
                @php
                    $unread = $row->read_at === null;
                    $line = Voice::line($row->data['key'] ?? '', $row->data['replace'] ?? [], for: auth()->user());
                @endphp

                <button
                    type="button"
                    wire:click="open('{{ $row->id }}')"
                    wire:key="note-{{ $row->id }}"
                    @class([
                        'flex w-full items-start gap-3 rounded-xl border px-4 py-3 text-start transition-colors',
                        'border-blue-200 bg-blue-50/50 hover:border-blue-300 dark:border-blue-900 dark:bg-blue-950/20' => $unread,
                        'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600' => ! $unread,
                    ])
                >
                    {{-- The unread mark is a dot, not a weight change: bold
                         text that un-bolds on tap reads as the row breaking. --}}
                    <span @class([
                        'mt-1.5 size-2 shrink-0 rounded-full',
                        'bg-blue-500' => $unread,
                        'bg-transparent' => ! $unread,
                    ])></span>

                    <span class="min-w-0 flex-1">
                        {{-- Render-guarded: a key that no longer resolves
                             leaves a dated row rather than an empty one. --}}
                        <span class="block text-sm leading-snug">
                            {{ $line !== '' ? $line : 'This one is no longer available.' }}
                        </span>
                        <span class="text-micro block pt-1 text-zinc-500 dark:text-zinc-400">
                            {{ $row->created_at->setTimezone(auth()->user()->timezone)->diffForHumans() }}
                        </span>
                    </span>

                    <flux:icon name="chevron-right" variant="micro" class="mt-1 shrink-0 text-zinc-400" />
                </button>
            @endforeach
        </div>
    @else
        <flux:callout icon="bell">
            <flux:callout.heading>Nothing yet</flux:callout.heading>
            <flux:callout.text>{{ Voice::line('notify.inbox.empty') }}</flux:callout.text>
        </flux:callout>
    @endif
</div>
