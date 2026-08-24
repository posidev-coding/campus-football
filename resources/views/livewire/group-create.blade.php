<?php

use App\Actions\CreateGroup;
use App\Enums\ContestMode;
use App\Exceptions\PickemParticipationGated;
use App\Support\Voice;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * THE CREATION WIZARD — a season-start configuration moment, not a form:
 * Name → The Game → the invite moment. The group exists only after the
 * final submit (backing out of step two leaves no orphan), and the mode
 * chosen here is the group's game for the WHOLE season — one deliberate,
 * announced pivot allowed, which the fine print says out loud before the
 * choice is made.
 *
 * Step three is the payoff: ONE LINK to copy or share — the way a group
 * travels now — with the code beneath it as the spoken-word fallback,
 * because a league of one is just a diary and the invite is how it stops
 * being one.
 */
new class extends Component
{
    public int $step = 1;

    public string $name = '';

    public string $mode = '';

    public ?int $groupId = null;

    public string $code = '';

    /** The link a group travels by, crediting the creator when handled. */
    #[Computed]
    public function joinUrl(): string
    {
        return route('pickem.join', array_filter([
            'code' => $this->code,
            'by' => auth()->user()->handle,
        ]));
    }

    public function toGame(): void
    {
        $this->validate(['name' => ['required', 'string', 'min:3', 'max:40']]);

        $this->step = 2;
    }

    public function back(): void
    {
        $this->step = 1;
    }

    public function choose(string $mode): void
    {
        $chosen = ContestMode::tryFrom($mode);

        // The Woodshed's card renders disabled, but a public Livewire
        // method is reachable without the button — the gate lives here
        // (and again in the Action, where it throws).
        if ($chosen === null || ! $chosen->available()) {
            return;
        }

        $this->mode = $chosen->value;
    }

    public function create(CreateGroup $action)
    {
        $chosen = ContestMode::tryFrom($this->mode);

        if ($this->step !== 2 || $chosen === null) {
            $this->addError('mode', 'Pick the mode your group plays.');

            return;
        }

        try {
            $group = $action->handle(auth()->user(), trim($this->name), $chosen);
        } catch (PickemParticipationGated) {
            $this->addError('mode', Voice::line('groups.verify_first'));

            return;
        }

        $this->groupId = $group->id;
        $this->code = $group->code;
        $this->step = 3;
    }
}; ?>

<div class="flex flex-col gap-5 lg:mx-auto lg:w-full lg:max-w-xl">
    <h1 class="sr-only">Start a group</h1>

    <div class="flex items-center justify-between gap-3">
        <flux:heading size="xl">
            @if ($step === 1)
                Name your group
            @elseif ($step === 2)
                Pick your game
            @else
                You're live
            @endif
        </flux:heading>

        <x-signup-progress :step="$step" :total="3" />
    </div>

    @if ($step === 1)
        <div wire:key="create-step-name" class="flex flex-col gap-3">
            <flux:subheading>{{ Voice::line('create.subheading') }}</flux:subheading>

            <livewire:verify-callout :body-key="'verify.picks.body'" :dismissable="false" @email-verified="$refresh" />

            <form wire:submit="toGame" class="flex flex-col gap-3">
                <flux:input
                    wire:model="name"
                    label="Group name"
                    maxlength="40"
                    autofocus
                />
                <flux:button type="submit" variant="primary" class="self-start">Next</flux:button>
            </form>
        </div>
    @elseif ($step === 2)
        <div wire:key="create-step-mode" class="flex flex-col gap-3">
            <div class="flex flex-col gap-2" role="radiogroup" aria-label="Contest mode">
                @foreach (App\Enums\ContestMode::cases() as $option)
                    <x-mode-card
                        wire:key="mode-{{ $option->value }}"
                        wire:click="choose('{{ $option->value }}')"
                        :mode="$option"
                        :selected="$mode === $option->value"
                    />
                @endforeach
            </div>

            {{-- The one-season rule, told BEFORE the choice — plain fine
                 print, because a rule a joke eats is a rule nobody heard. --}}
            <p class="text-stat text-zinc-500 dark:text-zinc-400">
                {{ Voice::line('create.mode.hint') }}
            </p>

            @error('mode')
                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            <div class="flex items-center gap-2">
                <flux:button wire:click="back" variant="ghost">Back</flux:button>
                <flux:button wire:click="create" variant="primary" :disabled="$mode === ''">
                    Create the group
                </flux:button>
            </div>
        </div>
    @else
        <div
            wire:key="create-step-invite"
            class="flex flex-col gap-4"
            x-data="{
                copied: false,
                canShare: typeof navigator.share === 'function',
                copy() {
                    navigator.clipboard.writeText(@js($this->joinUrl));
                    this.copied = true;
                    setTimeout(() => this.copied = false, 2000);
                },
                share() {
                    navigator.share({
                        title: @js($name),
                        text: @js(Voice::line('groups.invite.share_text', ['group' => $name])),
                        url: @js($this->joinUrl),
                    }).catch(() => {});
                },
            }"
        >
            <flux:subheading>{{ Voice::line('groups.created', ['group' => $name]) }}</flux:subheading>

            {{-- THE INVITE MOMENT: the link is the product here — one tap
                 and it travels. The code stays beneath as the spoken-word
                 fallback for a friend across the room. --}}
            <div class="flex flex-col items-center gap-3 rounded-xl border border-zinc-200 px-4 py-6 dark:border-zinc-700">
                <p class="text-micro font-medium uppercase tracking-wide text-zinc-400">Invite link</p>
                <p class="max-w-full truncate font-mono text-sm font-semibold">{{ Str::after($this->joinUrl, '://') }}</p>

                <div class="flex items-center gap-2">
                    <flux:button x-on:click="copy()" size="sm" variant="primary">
                        <span x-show="! copied">Copy link</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </flux:button>

                    <flux:button x-show="canShare" x-cloak x-on:click="share()" size="sm">
                        <flux:icon.box-arrow-up variant="micro" />
                        Share
                    </flux:button>
                </div>

                <div class="flex flex-col items-center gap-1 border-t border-zinc-100 pt-3 dark:border-zinc-800/60">
                    <p class="text-micro text-zinc-400">Or read them the code</p>
                    <p class="font-mono text-2xl font-bold tracking-[0.3em]">{{ $code }}</p>
                </div>
            </div>

            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ Voice::line('groups.invite.hint', ['group' => $name]) }}
            </p>

            <flux:button
                :href="route('pickem.group', $groupId)"
                wire:navigate
                variant="primary"
                class="self-start"
            >
                Go to your group
            </flux:button>
        </div>
    @endif
</div>
