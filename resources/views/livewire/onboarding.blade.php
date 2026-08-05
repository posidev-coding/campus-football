<?php

use App\Enums\ContentRating;
use App\Livewire\Concerns\PicksTeams;
use App\Models\User;
use App\Support\Voice;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Guided setup, in place.
 *
 * A guest steps through four small screens and lands, logged in, in the same
 * team picker a signed-in user sees immediately. It is an overlay rather than
 * a route for the reason the search panel is: never navigate away from the
 * page the user is trying to fill in.
 *
 * Why four screens instead of the one form at /register — which still exists
 * for bookmarks — is conversion, not decoration: people finish a sequence of
 * single questions that they abandon as a wall of fields. Credentials come
 * LAST, once they are invested, which also means an abandoned signup has no
 * password or email to leave lying around.
 */
new class extends Component
{
    use PicksTeams;

    public const STEPS = ['name', 'handle', 'rating', 'credentials'];

    /**
     * `?start=team` is how the post-registration redirect lands here already
     * authenticated, on the picker rather than back at step one.
     */
    #[Url(as: 'start', except: '')]
    public string $start = '';

    public string $step = 'name';

    public string $first_name = '';

    public string $last_name = '';

    public string $handle = '';

    public string $content_rating = 'pg13';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        // Signed in, there is nothing to sign up for — the whole flow IS the
        // picker. A guest who somehow arrives with ?start=team still has to
        // make an account first, so auth decides, not the querystring.
        $this->step = auth()->check() ? 'team' : 'name';
    }

    /**
     * Everything the picker trait needs; also what the confirmation list on
     * the last screen renders.
     */
    #[Computed]
    public function followedTeams()
    {
        return auth()->user()?->followedTeams()->get(['teams.id', 'slug', 'display_name', 'logo', 'logo_dark'])
            ?? collect();
    }

    /**
     * Validate ONLY the current screen, then advance.
     *
     * The point of splitting the form: a handle someone else already has is a
     * problem to solve on the handle screen, not a surprise four screens later
     * with everything else already typed.
     */
    public function next(): void
    {
        $this->validate($this->rulesFor($this->step), $this->messages());

        $index = array_search($this->step, self::STEPS, true);

        $this->step = self::STEPS[$index + 1] ?? 'credentials';
    }

    public function back(): void
    {
        $index = array_search($this->step, self::STEPS, true);

        if ($index > 0) {
            $this->step = self::STEPS[$index - 1];
        }
    }

    /**
     * Create the account, then hand off to the picker.
     *
     * Everything is validated again together — the per-screen passes are for
     * feedback, not trust; a client can post straight here.
     */
    public function register(): void
    {
        $validated = $this->validate(
            collect(self::STEPS)->flatMap(fn (string $step) => $this->rulesFor($step))->all(),
            $this->messages(),
        );

        $validated['password'] = Hash::make($validated['password']);

        event(new Registered($user = User::create($validated)));

        Auth::login($user);
        session()->regenerate();

        /*
         * A FULL redirect, not `navigate: true`. Registering flips the whole
         * page's auth state — the bottom tab bar, the header, Home's own
         * branches — and a hard load re-renders all of it rather than hoping
         * a morph catches every `@auth` region.
         */
        $this->redirect(route('home', ['start' => 'team']));
    }

    /** Finish: stop the prompt coming back, and close. */
    public function done(): void
    {
        $this->markOnboarded();

        $this->dispatch('close-onboarding');
    }

    protected function afterTeamAdded(\App\Models\Team $team): void
    {
        // Following a team IS completing onboarding — the CTA must not return
        // on the next visit to a page that now has their team on it.
        $this->markOnboarded();

        unset($this->followedTeams);

        // Home rerenders its glance cards from this.
        $this->dispatch('team-followed');
    }

    private function markOnboarded(): void
    {
        $user = auth()->user();

        if ($user !== null && ! $user->hasOnboarded()) {
            $user->forceFill(['onboarded_at' => now()])->save();
        }
    }

    /**
     * Typing a capital should not become a validation error to read and fix.
     */
    public function updatedHandle(): void
    {
        $this->handle = Str::of($this->handle)->lower()->replaceMatches('/[^a-z0-9_]/', '')->substr(0, 20)->toString();
    }

    /**
     * The same rules `auth/register` uses, split by screen.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rulesFor(string $step): array
    {
        return match ($step) {
            'name' => [
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
            ],
            'handle' => [
                'handle' => [
                    'required', 'string', 'min:3', 'max:20',
                    'regex:/^[a-z0-9_]+$/',
                    'unique:'.User::class,
                ],
            ],
            'rating' => ['content_rating' => ['required', Rule::enum(ContentRating::class)]],
            'credentials' => [
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
                'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
            ],
            default => [],
        };
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return ['handle.regex' => 'Handles use lowercase letters, numbers and underscores.'];
    }

    #[Computed]
    public function stepNumber(): int
    {
        return (int) array_search($this->step, self::STEPS, true) + 1;
    }
}; ?>

<div
    x-data="{
        open: @js(request()->query('start') === 'team' && auth()->check()),

        /*
         * The in-progress answers survive a closed overlay, so an abandoned
         * signup can be picked up later.
         *
         * ONLY the first three screens are stored. The password and email live
         * on the last screen, so credentials are excluded BY CONSTRUCTION
         * rather than by a denylist someone widens later without noticing what
         * it was protecting. Do not add them here.
         */
        key: 'cfb.signup',

        /*
         * The ONLY fields that may ever be written. `password`, its
         * confirmation and `email` are absent, and the credentials screen
         * carries no save handler at all — two independent protections, so
         * neither a widened allowlist nor a stray handler leaks a credential
         * on its own.
         */
        fields: ['first_name', 'last_name', 'handle', 'content_rating'],

        restore() {
            if (@js(auth()->check())) return

            let draft = {}
            try { draft = JSON.parse(localStorage.getItem(this.key) || '{}') } catch (e) { return }

            for (const field of this.fields) {
                if (draft[field]) $wire.set(field, draft[field], false)
            }
        },

        /*
         * Read from the ELEMENT that fired, never from `$wire`. The model
         * bindings here are deferred, so `$wire.handle` is still empty while
         * the user is typing into it — saving from `$wire` wrote a whole step
         * behind and, worse, could attribute one field's text to another
         * across a morph.
         */
        save(e) {
            if (@js(auth()->check())) return

            const field = e.target.getAttribute('wire:model')

            if (! this.fields.includes(field)) return

            let draft = {}
            try { draft = JSON.parse(localStorage.getItem(this.key) || '{}') } catch (err) {}

            draft[field] = e.target.value
            localStorage.setItem(this.key, JSON.stringify(draft))
        },

        clear() { localStorage.removeItem(this.key) },
    }"
    {{-- An authenticated visitor has no signup in progress, so any draft on
         this device is finished business — including the one the just-created
         account left behind, since `register()` redirects and the page unloads
         before anything client-side could tidy up. Clearing on the next load
         is what stops a stranger's name sitting in a shared browser. --}}
    x-init="@js(auth()->check()) ? clear() : restore()"
    @start-onboarding.window="open = true"
    @close-onboarding.window="open = false; clear()"
    @keydown.escape.window="open = false"
>
    <div x-cloak x-show="open" class="fixed inset-0 z-50 flex flex-col bg-white pt-[env(safe-area-inset-top)] dark:bg-zinc-950">
        {{-- Closable at every step, per the brief: nobody is trapped in a
             signup they changed their mind about. --}}
        <div class="flex items-center justify-between gap-2 border-b border-zinc-200 px-4 py-2 dark:border-zinc-800">
            @if ($step !== 'team' && $step !== 'name')
                <flux:button wire:click="back" size="sm" variant="ghost" icon="chevron-left">Back</flux:button>
            @else
                <span></span>
            @endif

            @guest
                @if ($step !== 'team')
                    <span class="text-micro text-zinc-500">Step {{ $this->stepNumber }} of {{ count(self::STEPS) }}</span>
                @endif
            @endguest

            <flux:button @click="open = false" size="sm" variant="ghost" icon="x-mark" aria-label="Close" />
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-6 pb-[calc(env(safe-area-inset-bottom)+1.5rem)]">
            <div class="mx-auto flex w-full max-w-md flex-col gap-5">
                @if ($step === 'name')
                    <flux:heading size="xl">{{ Voice::line('onboarding.name') }}</flux:heading>

                    {{-- `wire:key` per step is load-bearing, not tidying.
                         Without it Livewire MORPHS step one's input into step
                         two's — same tag, same position — and the reused node
                         carried its old binding long enough for a keystroke to
                         land on the previous field. --}}
                    <div class="flex flex-col gap-3" @input="save($event)" wire:key="step-name">
                        <flux:input wire:model="first_name" label="First name" autocomplete="given-name" />
                        <flux:input wire:model="last_name" label="Last name" autocomplete="family-name" />
                    </div>
                @elseif ($step === 'handle')
                    <flux:heading size="xl">Pick a handle</flux:heading>

                    <div @input="save($event)" wire:key="step-handle">
                        {{-- The format rule stays plain instruction. A joke
                             between a reader and the thing they have to get
                             right is friction, not personality. --}}
                        <flux:input
                            wire:model="handle"
                            x-mask:dynamic="$input.toLowerCase().replace(/[^a-z0-9_]/g, '').slice(0, 20)"
                            label="Handle"
                            placeholder="dawgpound99"
                            autocomplete="username"
                            description="Lowercase letters, numbers and underscores. This is how you show up on leaderboards."
                        >
                            <x-slot name="iconLeading">
                                <span class="ps-3 text-sm text-zinc-400">&#64;</span>
                            </x-slot>
                        </flux:input>
                    </div>
                @elseif ($step === 'rating')
                    <flux:heading size="xl">{{ Voice::line('onboarding.rating') }}</flux:heading>

                    <div @change="save($event)" wire:key="step-rating">
                        <flux:radio.group
                            wire:model="content_rating"
                            description="We roast your picks, your team and your record — never you."
                            variant="cards"
                            class="flex-col"
                        >
                            @foreach (ContentRating::cases() as $rating)
                                <flux:radio
                                    :value="$rating->value"
                                    :label="$rating->label()"
                                    :description="$rating->description()"
                                >
                                    <span class="flex items-baseline gap-2">
                                        <span class="font-medium">{{ $rating->label() }}</span>
                                        <span class="text-sm text-zinc-500">{{ $rating->subLabel() }}</span>
                                    </span>
                                </flux:radio>
                            @endforeach
                        </flux:radio.group>
                    </div>
                @elseif ($step === 'credentials')
                    <flux:heading size="xl">{{ Voice::line('onboarding.credentials') }}</flux:heading>

                    {{-- No save handler here, and that is the point:
                         nothing on this screen is ever written to the device. --}}
                    <div class="flex flex-col gap-3" wire:key="step-credentials">
                        <flux:input wire:model="email" type="email" label="Email" autocomplete="email" />
                        <flux:input wire:model="password" type="password" label="Password" autocomplete="new-password" viewable />
                        <flux:input wire:model="password_confirmation" type="password" label="Confirm password" autocomplete="new-password" viewable />
                    </div>
                @else
                    {{-- The team picker. Signed-in users start here. --}}
                    <flux:heading size="xl">
                        {{ $this->followedTeams->isEmpty() ? 'Who do you follow?' : Voice::line('onboarding.done') }}
                    </flux:heading>

                    @if ($this->followedTeams->isNotEmpty())
                        <div class="flex flex-col gap-2">
                            @foreach ($this->followedTeams as $team)
                                <div class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800" wire:key="added-{{ $team->id }}">
                                    <x-team-logo :team="$team" size="sm" />
                                    <span class="min-w-0 flex-1 truncate text-sm">{{ $team->display_name }}</span>
                                    <flux:icon name="check" variant="micro" class="shrink-0 text-emerald-600 dark:text-emerald-400" />
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($this->canFollowMore)
                        <div class="flex flex-col gap-2">
                            <flux:input
                                wire:model.live.debounce.250ms="teamQuery"
                                icon="magnifying-glass"
                                placeholder="Search FBS teams…"
                            />

                            @if ($followError)
                                <p class="text-micro text-amber-600 dark:text-amber-500">{{ $followError }}</p>
                            @endif

                            @forelse ($this->teamMatches as $match)
                                <button
                                    type="button"
                                    wire:click="addTeam({{ $match['id'] }})"
                                    wire:key="pick-{{ $match['id'] }}"
                                    class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-left text-sm transition-colors hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:border-zinc-700 dark:hover:bg-zinc-900"
                                >
                                    <span class="min-w-0 truncate">{{ $match['name'] }}</span>
                                    <flux:icon name="plus" variant="micro" class="shrink-0 text-zinc-400" />
                                </button>
                            @empty
                                @if (trim($teamQuery) !== '')
                                    <p class="px-1 text-micro text-zinc-500">
                                        {{ Voice::line('teams.no_matches', ['query' => trim($teamQuery)]) }}
                                    </p>
                                @endif
                            @endforelse
                        </div>
                    @endif
                @endif
            </div>
        </div>

        {{-- The action rail, pinned to the bottom so the primary button is
             under a thumb rather than below the fold on a small screen. --}}
        <div class="border-t border-zinc-200 px-4 py-3 pb-[calc(env(safe-area-inset-bottom)+0.75rem)] dark:border-zinc-800">
            <div class="mx-auto w-full max-w-md">
                @if ($step === 'credentials')
                    <flux:button wire:click="register" variant="primary" class="w-full">Create account</flux:button>
                @elseif ($step === 'team')
                    <flux:button
                        wire:click="done"
                        @click="clear()"
                        variant="primary"
                        class="w-full"
                    >
                        {{ $this->followedTeams->isEmpty() ? 'Skip for now' : 'Done' }}
                    </flux:button>
                @else
                    <flux:button wire:click="next" variant="primary" class="w-full">Continue</flux:button>
                @endif
            </div>
        </div>
    </div>
</div>
