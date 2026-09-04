<?php

use App\Actions\FollowTeam;
use App\Actions\ReorderFollowedTeams;
use App\Actions\UnfollowTeam;
use App\Enums\ContentRating;
use App\Exceptions\FollowLimitReached;
use App\Livewire\Concerns\UploadsImages;
use App\Models\Team;
use App\Models\TeamSeason;
use App\Models\User;
use App\Services\CfbCalendar;
use App\Support\ImageUpload;
use App\Support\PhoneNumber;
use App\Support\Voice;
use Flux\Flux;
use App\Notifications\VerifyPhoneNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Account settings, and the teams a user cares about.
 *
 * Following a team dispatches that team's news fetch — see FollowTeam — so
 * this screen is what populates a user's home page and the team's News tab.
 *
 * The ORDER of the list is the model: it drives the Home swipe order, the
 * scoreboard float order, and whose news leads. There is no separate favorite
 * anymore; position 1 is what that meant.
 */
new class extends Component
{
    use UploadsImages;
    use WithFileUploads;

    public string $first_name = '';

    public string $last_name = '';

    public string $handle = '';

    public string $content_rating = '';

    /**
     * The weekly email.
     *
     * `live` rather than part of the profile modal: it is a switch, not a form
     * — there is nothing to review before saving, and burying an email
     * preference behind a save button is how somebody ends up unsubscribing
     * through the footer link instead.
     */
    public bool $newsletter_opt_in = true;

    /**
     * The pick'em loop — reminders and results.
     *
     * Its own switch rather than a second meaning for the newsletter's: the
     * two lists answer different questions, and the unsubscribe footer on
     * each names which one it silences.
     */
    public bool $pickem_notify_opt_in = true;

    /** The pending upload. Null unless a file is mid-flight. */
    public $photo = null;

    /* ── SMS ──────────────────────────────────────────────────────────────
     *
     * Three fields for what looks like one control, because consent and
     * identity are separate claims: the number they typed, the code proving
     * they hold it, and the explicit yes.
     */
    public string $phone = '';

    /** The 6-digit code, shown only while a verification is outstanding. */
    public string $phone_code = '';

    public bool $sms_opt_in = false;


    /**
     * The follow search query.
     *
     * A plain text input rather than a searchable listbox. Flux's listbox does
     * focus its inner search box on open, but that focus is PROGRAMMATIC, which
     * on touch does not raise the keyboard — so a phone user taps the control,
     * gets a popover, and has to tap again to type. Tapping a real input raises
     * the keyboard because the user touched it.
     */
    public string $teamSearch = '';

    /** Set when adding a team would exceed the follow limit. */
    public string $followError = '';

    public function mount(): void
    {
        $this->fillProfileForm();
    }

    /**
     * Reset the form to what is stored.
     *
     * Called on open as well as on mount, so abandoning an edit and reopening
     * does not present the half-typed values from last time as though they had
     * been saved.
     */
    public function fillProfileForm(): void
    {
        $user = auth()->user();

        $this->first_name = $user->first_name;
        $this->last_name = $user->last_name;
        // Coalesced: the column is null until claimed, and this is a typed
        // string property — a bare null assignment is a TypeError, not a
        // validation message.
        $this->handle = $user->handle ?? '';
        $this->content_rating = $user->content_rating->value;
        $this->newsletter_opt_in = $user->newsletter_opt_in;
        $this->pickem_notify_opt_in = $user->pickem_notify_opt_in;
        $this->phone = PhoneNumber::format($user->phone) ?? '';
        $this->sms_opt_in = $user->sms_opt_in;
    }

    /**
     * Is there a code outstanding? Drives whether the form asks for a number or
     * for the digits we just sent to it.
     */
    #[Computed]
    public function awaitingCode(): bool
    {
        return Cache::has($this->codeKey());
    }

    /**
     * Send a one-time code to the number they typed.
     *
     * The number is NOT stored yet. Writing it before it is proved would let
     * anybody park a stranger's phone on their account, and every later send
     * would then be to somebody who never heard of us.
     */
    public function sendPhoneCode(): void
    {
        /* A fresh attempt clears the previous complaint. Without this the last
           error stays on screen through a successful retry, which reads as the
           retry having failed too. */
        $this->resetValidation();

        $normalized = PhoneNumber::normalize($this->phone);

        $this->validate(
            ['phone' => ['required']],
            ['phone.required' => 'Add a mobile number first.'],
        );

        if ($normalized === null) {
            $this->addError('phone', 'That does not look like a mobile number. US numbers can be 10 digits; anything else needs a country code.');

            return;
        }

        /*
         * One code a minute, and the limit is per USER rather than per number:
         * keyed on the number, somebody could walk through a range and use us
         * as a free SMS cannon at our expense. This notification carries no
         * daily budget middleware because it is transactional, so this rate
         * limit is the only thing standing between a form and a bill.
         */
        $throttle = 'phone-code:'.auth()->id();

        if (RateLimiter::tooManyAttempts($throttle, 1)) {
            $this->addError('phone', 'Wait a moment before asking for another code.');

            return;
        }

        RateLimiter::hit($throttle, 60);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->codeKey(), ['code' => $code, 'phone' => $normalized], now()->addMinutes(10));

        /* Addressed to the number directly: routeNotificationForVonage() gates
           on the number already being verified, which is what this establishes. */
        Notification::route('vonage', $normalized)
            ->notify(new VerifyPhoneNotification($code));

        unset($this->awaitingCode);
    }

    /**
     * Check the code, and only then store the number.
     */
    public function confirmPhoneCode(): void
    {
        $this->resetValidation();

        $pending = Cache::get($this->codeKey());

        if ($pending === null) {
            $this->addError('phone_code', 'That code has expired. Ask for another.');
            unset($this->awaitingCode);

            return;
        }

        if (! hash_equals($pending['code'], trim($this->phone_code))) {
            $this->addError('phone_code', 'That code is not right.');

            return;
        }

        auth()->user()->forceFill([
            'phone' => $pending['phone'],
            'phone_verified_at' => now(),
        ])->save();

        Cache::forget($this->codeKey());

        $this->phone_code = '';
        $this->phone = PhoneNumber::format($pending['phone']) ?? '';

        unset($this->awaitingCode);
    }

    /**
     * Forget the number entirely. Clears consent with it — consent to text a
     * number we no longer hold means nothing, and leaving it set would text the
     * NEXT number they add without asking again.
     */
    public function removePhone(): void
    {
        auth()->user()->forceFill([
            'phone' => null,
            'phone_verified_at' => null,
            'sms_opt_in' => false,
        ])->save();

        Cache::forget($this->codeKey());

        $this->phone = '';
        $this->phone_code = '';
        $this->sms_opt_in = false;

        unset($this->awaitingCode);
    }

    /**
     * The explicit yes, timestamped.
     *
     * `sms_opted_in_at` is stamped on the way IN and never cleared on the way
     * out: it records that consent once happened, which stays true afterwards
     * and is what a carrier asks to see when vetting the 10DLC campaign.
     */
    public function updatedSmsOptIn(bool $value): void
    {
        $user = auth()->user();

        if ($value && $user->phone_verified_at === null) {
            $this->sms_opt_in = false;
            $this->addError('sms_opt_in', 'Add and confirm a mobile number first.');

            return;
        }

        $user->forceFill([
            'sms_opt_in' => $value,
            'sms_opted_in_at' => $value ? now() : $user->sms_opted_in_at,
        ])->save();
    }

    /** Cache key for the outstanding code — per user, never per number. */
    private function codeKey(): string
    {
        return 'phone-verify:'.auth()->id();
    }

    /**
     * Turning it back on clears nothing: `unsubscribed_at` records that they
     * once said no, and that stays true.
     */
    /**
     * Turning it off stamps `unsubscribed_at` the same way the newsletter
     * does — the column records that this person once said no to something,
     * which stays true whichever list it was.
     */
    public function updatedPickemNotifyOptIn(bool $value): void
    {
        auth()->user()->forceFill([
            'pickem_notify_opt_in' => $value,
            'unsubscribed_at' => $value ? auth()->user()->unsubscribed_at : now(),
        ])->save();
    }

    public function updatedNewsletterOptIn(bool $value): void
    {
        auth()->user()->forceFill([
            'newsletter_opt_in' => $value,
            'unsubscribed_at' => $value ? auth()->user()->unsubscribed_at : now(),
        ])->save();
    }

    public function saveProfile(): void
    {
        $user = auth()->user();

        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            /*
             * `ignore($user)` so saving without touching the handle is not a
             * collision with yourself. The rule sits on a unique index over a
             * case-insensitive collation, so the database is the real guarantee
             * — this is the readable error, not the enforcement.
             *
             * `nullable` only until claimed: registration stopped asking for a
             * handle, so a handleless user must be able to save their name and
             * rating without being marched through the claim. Once claimed it
             * is `required` — a handle can change, but never blank back to
             * nothing with mentions of it possibly in the wild.
             */
            'handle' => [
                $user->handle === null ? 'nullable' : 'required',
                'string', 'min:3', 'max:20',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('users')->ignore($user->id),
            ],
            'content_rating' => ['required', Rule::enum(ContentRating::class)],
        ], [
            'handle.regex' => 'Handles use lowercase letters, numbers and underscores.',
        ]);

        // An empty field means "not claiming yet", never a write of '' — the
        // column stays null so the claim affordance knows to keep offering.
        if (($validated['handle'] ?? '') === '') {
            $validated['handle'] = $user->handle;
        }

        $user->update($validated);

        Flux::modal('edit-profile')->close();
    }

    /**
     * Stored the moment a file is chosen, rather than behind the modal's save
     * button: a photo is not a field somebody wants to review before
     * committing, and seeing it appear is the confirmation.
     */
    public function updatedPhoto(): void
    {
        $this->validate([
            /*
             * The cap and its reasoning moved to ImageUpload, where the
             * BROWSER can read the same number: a photo straight off a phone
             * camera is five to ten times this, and PHP drops a body that big
             * before any rule here gets to run. This is the backstop now.
             */
            'photo' => ImageUpload::rules(),
        ], [
            'photo.mimes' => ImageUpload::mimeMessage(),
            'photo.max' => ImageUpload::oversizedMessage(),
            'photo.dimensions' => 'That image is too small to read at avatar size.',
        ]);

        $user = auth()->user();
        $previous = $user->avatar;

        try {
            $path = $this->photo->store('avatars', config('cfb.upload_disk'));

            // A disk with `throw => false` returns FALSE instead of raising,
            // and that false would blank the avatar and look like a success.
            if (! is_string($path) || $path === '') {
                throw new \RuntimeException('The upload disk refused the avatar write and returned no path.');
            }
        } catch (\Throwable $e) {
            // The disk refused. Report it and say so on the photo's own
            // error line rather than a 500 — the avatar stays what it was.
            report($e);
            $this->photo = null;
            $this->addError('photo', Voice::line('account.photo.failed'));

            return;
        }

        $user->forceFill(['avatar' => $path])->save();

        /*
         * Delete AFTER the new path is committed, never before. Reversed, a
         * failed upload leaves the user with no avatar and no way back to the
         * one they had.
         */
        if (filled($previous) && $previous !== $path) {
            Storage::disk(config('cfb.upload_disk'))->delete($previous);
        }

        $this->photo = null;
    }

    /**
     * Typing a capital should not become a validation error to read and fix.
     */
    public function updatedHandle(): void
    {
        $this->handle = Str::of($this->handle)->lower()->replaceMatches('/[^a-z0-9_]/', '')->substr(0, 20)->toString();
    }

    /**
     * The drag path. `wire:sort` reports ONE item and its new index, not the
     * whole list, and the index is 0-based.
     */
    public function reorder(int $teamId, int $position, ReorderFollowedTeams $action): void
    {
        $action->place(auth()->user(), $teamId, $position);

        unset($this->followed);
    }

    /**
     * The keyboard path to the same outcome. A drag handle is unreachable
     * without a pointer, and the order is the only way to say which team
     * leads — so it cannot be pointer-only.
     */
    public function move(int $teamId, int $offset, ReorderFollowedTeams $action): void
    {
        $action->move(auth()->user(), $teamId, $offset);

        unset($this->followed);
    }

    public function follow(int $teamId, FollowTeam $action): void
    {
        $team = Team::find($teamId);

        $this->followError = '';

        if ($team === null) {
            return;
        }

        try {
            $action->handle(auth()->user(), $team);
        } catch (FollowLimitReached $e) {
            $this->followError = Voice::line('follow.limit', ['max' => $e->limit]);

            return;
        }

        // Cleared on success only. A failed follow leaves the query in place so
        // the user can see what they were reaching for alongside the reason it
        // did not happen.
        $this->teamSearch = '';

        unset($this->followed, $this->followable, $this->matches);
    }

    public function unfollow(int $teamId, UnfollowTeam $action): void
    {
        $team = Team::find($teamId);

        if ($team !== null) {
            $action->handle(auth()->user(), $team);
        }

        // The freed slot has to show up in both the list and the picker, and
        // clearing the error matters too: unfollowing is exactly how a user
        // acts on "unfollow one to make room".
        $this->followError = '';

        unset($this->followed, $this->followable);
    }

    /**
     * FBS teams for the current season, so the picker is not 854 entries long.
     *
     * @return list<array{id:int, name:string}>
     */
    #[Computed]
    public function teams(): array
    {
        // Shared with Home's quick add, so the two pickers cannot drift and
        // only one of them pays for the query.
        return \App\Support\TeamGlance::fbsTeams();
    }

    /**
     * Teams available to follow — everything they are not already following.
     *
     * Offering a team already in the list below is noise, and picking it would
     * be a no-op that looks like a broken control.
     *
     * @return list<array{id:int, name:string}>
     */
    #[Computed]
    public function followable(): array
    {
        $already = $this->followed->pluck('id')->all();

        return collect($this->teams)
            ->reject(fn (array $team) => in_array($team['id'], $already, true))
            ->values()
            ->all();
    }

    /**
     * Teams matching the query, capped so the card cannot grow without bound.
     *
     * Empty for an empty query: showing all 136 FBS teams under the input would
     * bury everything below it on a phone.
     *
     * @return list<array{id:int, name:string}>
     */
    #[Computed]
    public function matches(): array
    {
        $query = trim($this->teamSearch);

        if ($query === '') {
            return [];
        }

        return collect($this->followable)
            ->filter(fn (array $team) => str_contains(
                mb_strtolower($team['name']), mb_strtolower($query)
            ))
            ->take(6)
            ->values()
            ->all();
    }

    #[Computed]
    public function atLimit(): bool
    {
        return $this->followed->count() >= User::MAX_FOLLOWED_TEAMS;
    }

    /**
     * Followed teams, pinned one first, then the rest alphabetically.
     *
     * Same order the scoreboard floats them in, so the list is a preview of
     * what pinning actually does rather than a differently-sorted copy.
     * PHP's sort is stable, so the alphabetical run survives underneath.
     */
    #[Computed]
    public function followed()
    {
        $pinned = auth()->user()->favorite_team_id;

        return auth()->user()
            ->followedTeams()
            ->orderBy('display_name')
            ->get(['teams.id', 'slug', 'display_name', 'short_display_name', 'abbreviation', 'logo', 'logo_dark'])
            ->sortBy(fn (Team $team) => $team->id === $pinned ? 0 : 1)
            ->values();
    }
}; ?>

{{-- Two columns from `lg`: three stacked cards on a wide screen is a metre of
     scrolling past a lot of nothing. `items-start` so a short card does not
     stretch to match a tall one, and `gap-y-4` rather than `gap-6` so the
     vertical rhythm still matches the base `gap-4` the sticky heading's
     `-mt-5` was measured against.

     The followed-teams card keeps its list in ONE column whatever this does —
     it is a `wire:sort` drag target, and reflowing a sortable list into a grid
     breaks the ordering semantics SortableJS reports back. --}}
<div class="flex flex-col gap-4 lg:grid lg:grid-cols-2 lg:items-start lg:gap-x-6 lg:gap-y-4">
    {{-- Sticky, because this screen only grows — the heading has to stay put
         once settings run past a viewport.

         Same offsets as the scoreboard's chrome, and for the same reasons:
         `-mt-5` cancels the layout container's `py-5` so the block rests exactly
         where it sticks instead of drifting up on the first scroll, and the `sm`
         offset is 14 spacing units PLUS ONE PIXEL because the header is `h-14`
         plus its own `border-b`. --}}
    <div class="sticky top-[var(--chrome-offset)] z-30 -mx-4 -mt-5 flex items-center justify-between gap-3 bg-white px-4 pt-3 pb-2 lg:col-span-2 dark:bg-zinc-950">
        {{-- The word "Account" is gone from the page. The bottom tab that got
             you here is already lit and already says it, so the heading was
             the screen naming itself twice — the same reason every League
             screen's h1 is sr-only. The brand takes the space instead, which
             is what the top-left of an app screen is for — below `sm` only,
             because from `sm` the header's own lockup is a line above and two
             brands in two rows is the same screen naming itself twice again. --}}
        <x-brand.lockup size="md" class="sm:hidden" />
        <h1 class="sr-only">Account</h1>

        {{-- Icon-only, and shared with the avatar menu — the partial owns the
             full rationale. Up here because the three labels would not fit
             beside the heading at 390px at all. --}}
        <x-appearance-switcher class="shrink-0" />
    </div>

    {{-- The verify nudge, spanning both columns: Account is where the email
         lives, so its absence of a checkmark is most conspicuous here. The
         component renders nothing once verified. --}}
    <livewire:verify-callout class="lg:col-span-2" @email-verified="$refresh" />

    <flux:card class="flex flex-col gap-3">
        <div class="flex items-center gap-3">
            {{-- The avatar IS the control. A separate "change photo" button
                 would be a second thing to place on a row that is already
                 tight at 390px, and tapping your own face to change it is the
                 gesture every other app has taught. The label wraps a hidden
                 input so it stays keyboard-reachable and screen-reader-named,
                 which a click handler on a div would not be. --}}
            <label class="group relative shrink-0 cursor-pointer" title="Change your photo">
                <flux:avatar
                    :src="auth()->user()->avatarUrl()"
                    :initials="auth()->user()->initials()"
                />

                {{-- Only on hover and focus, so the face is not permanently
                     wearing a badge. --}}
                <span
                    class="absolute inset-0 flex items-center justify-center rounded-full bg-zinc-900/60 text-white opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100"
                    aria-hidden="true"
                >
                    <flux:icon name="camera" variant="micro" />
                </span>

                <x-image-file-input property="photo" label="Upload a profile photo" />
            </label>

            {{-- The one place the upload can report on itself. `wire:target`
                 keeps it from firing on every other write this component
                 makes. --}}
            <flux:icon.loading wire:loading wire:target="photo" class="size-4 shrink-0 text-zinc-400" />

            <div class="flex min-w-0 flex-col">
                <span class="truncate font-medium">{{ auth()->user()->name }}</span>
                {{-- The handle leads, the email follows: the handle is what
                     other people see, the email is only how you sign in.
                     Null means never claimed — never a fabricated stand-in;
                     the claim opens the same modal the pencil does. --}}
                @if (auth()->user()->handle !== null)
                    <span class="truncate text-sm text-zinc-500">&#64;{{ auth()->user()->handle }}</span>
                @else
                    <flux:modal.trigger name="edit-profile">
                        <button
                            type="button"
                            wire:click="fillProfileForm"
                            class="truncate text-start text-sm font-medium text-blue-600 hover:underline dark:text-blue-400"
                        >
                            {{ Voice::line('profile.claim_handle') }}
                        </button>
                    </flux:modal.trigger>
                @endif
                <span class="truncate text-micro text-zinc-400">{{ auth()->user()->email }}</span>
            </div>

            {{-- Reset on open, not only on mount: abandoning an edit and coming
                 back should show what is stored, not last time's typing. --}}
            <flux:modal.trigger name="edit-profile">
                <flux:button
                    wire:click="fillProfileForm"
                    size="xs"
                    variant="ghost"
                    icon="pencil-square"
                    class="ms-auto shrink-0"
                    aria-label="Edit your profile"
                />
            </flux:modal.trigger>
        </div>

        {{-- Below the row rather than beside the avatar: the messages are a
             sentence long and there is no width for them at 390px. Without
             this the upload validation has nowhere to land and a rejected
             photo looks like nothing happened at all. --}}
        <flux:error name="photo" />

        <flux:separator />

        <div class="flex items-center justify-between gap-3 text-sm">
            <span class="shrink-0 text-zinc-500">Trash talk</span>
            <flux:badge size="sm" class="shrink-0">
                {{ auth()->user()->content_rating->label() }} · {{ auth()->user()->content_rating->subLabel() }}
            </flux:badge>
        </div>

        <flux:separator />

        {{-- Saves on change rather than behind the profile modal's save button.
             It is a switch, not a form: there is nothing to review, and an
             email preference that takes three taps to reach is one people give
             up on and unsubscribe from the footer instead. --}}
        <flux:switch
            wire:model.live="newsletter_opt_in"
            label="Weekly email"
            description="How your teams did, and what's next. One a week, and you can stop it any time."
            align="right"
        />

        <flux:separator />

        {{-- A SEPARATE switch from the weekly email, because they are separate
             consents: plenty of people want to be told their picks are due and
             do not want a Sunday digest. One shared switch would mean the
             unsubscribe that stops the digest silently stops the reminders too,
             which reads as the app being broken rather than as a preference. --}}
        <flux:switch
            wire:model.live="pickem_notify_opt_in"
            label="Pick'em reminders and results"
            description="When your picks are due, and how the week finished. Only while you're in a group."
            align="right"
        />

        <flux:separator />

        {{--
            Push notifications — this DEVICE's, which is the honest unit: the
            permission and the subscription both live on the phone in hand,
            so there is no server column for a switch to disagree with (the
            subscription IS the consent — see User's trait comment).

            Alpine rather than wire:model because the whole flow is client
            capability: the switch's on position runs the permission prompt
            inside the tap (a spent prompt never re-asks), then subscribes
            and stores. A browser with no push support hides the row; a
            denied permission swaps the switch for the one honest sentence,
            since flipping a switch we know cannot prompt would be theater.
        --}}
        <div
            data-push-control
            x-cloak
            x-show="supported"
            x-data="{
                supported: false,
                denied: false,
                on: false,
                busy: false,

                async init() {
                    this.supported = window.cfbPush.supported();

                    if (! this.supported) return;

                    this.denied = window.cfbPush.permission() === 'denied';
                    this.on = await window.cfbPush.subscribed();

                    this.$watch('on', (value) => this.apply(value));
                },

                async apply(value) {
                    if (this.busy) return;

                    this.busy = true;

                    if (value) {
                        let result = await window.cfbPush.enable(
                            @js(config('webpush.vapid.public_key')),
                            @js(route('push.store')),
                        );

                        if (result !== 'granted') {
                            this.denied = result === 'denied';
                            this.on = false;
                        }
                    } else {
                        await window.cfbPush.disable(@js(route('push.destroy')));
                    }

                    this.busy = false;
                },
            }"
            class="flex flex-col gap-2"
        >
            <template x-if="! denied">
                <flux:switch
                    x-model="on"
                    label="Notifications"
                    description="Kickoff alerts for your teams, and what's coming with Pick'em. This device only."
                    align="right"
                />
            </template>

            <template x-if="denied">
                <div class="flex flex-col gap-1 text-sm">
                    <span class="font-medium">Notifications</span>
                    <span class="text-zinc-500 dark:text-zinc-400">
                        Notifications are blocked for this app in your device settings — flip them on there and this switch comes back.
                    </span>
                </div>
            </template>
        </div>

        <flux:separator />

        {{--
            Text messages.

            Three steps, shown one at a time, because they are three different
            claims: a number, proof it is yours, and an explicit yes. Collapsing
            them into one control is how an app ends up texting a stranger whose
            number somebody fat-fingered.

            The switch stays hidden until the number is confirmed. Offering
            consent before there is a verified number to attach it to means
            capturing a "yes" that cannot lawfully be acted on.
        --}}
        <div class="flex flex-col gap-3">
            @if (auth()->user()->phone_verified_at)
                <flux:switch
                    wire:model.live="sms_opt_in"
                    label="Text messages"
                    description="Reminders and alerts about your teams. Message and data rates may apply, and you can reply STOP to any message to end them."
                    align="right"
                />

                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="min-w-0 truncate text-zinc-500">
                        {{ $this->phone }}
                        <flux:badge size="sm" color="green" class="ms-1">Confirmed</flux:badge>
                    </span>

                    <flux:button wire:click="removePhone" size="xs" variant="ghost" class="shrink-0">
                        Remove
                    </flux:button>
                </div>

                <flux:error name="sms_opt_in" />
            @elseif ($this->awaitingCode)
                <form wire:submit="confirmPhoneCode" class="flex flex-col gap-2">
                    <flux:input
                        wire:model="phone_code"
                        label="Enter the code"
                        description="Sent to {{ $this->phone }}. It expires in ten minutes."
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                    />

                    <div class="flex gap-2">
                        <flux:button type="submit" wire:loading.attr="disabled" wire:target="confirmPhoneCode" size="sm" variant="primary">Confirm</flux:button>
                        <flux:button wire:click="removePhone" size="sm" variant="ghost">Cancel</flux:button>
                    </div>
                </form>
            @else
                <form wire:submit="sendPhoneCode" class="flex flex-col gap-2">
                    {{-- `inputmode="tel"` raises the phone keypad rather than the
                         full keyboard, which is the difference between typing a
                         number and composing one. --}}
                    <flux:input
                        wire:model="phone"
                        label="Mobile number"
                        description="Optional. We'll text a code to confirm it before anything else is sent."
                        placeholder="(415) 555-0123"
                        inputmode="tel"
                        autocomplete="tel"
                    />

                    <flux:button type="submit" wire:loading.attr="disabled" wire:target="sendPhoneCode" size="sm" variant="ghost" class="self-start">
                        Send me a code
                    </flux:button>
                </form>
            @endif
        </div>
    </flux:card>

    {{-- Email is absent on purpose. Changing it has to re-verify, which is its
         own flow with its own security consequences — quietly editing it beside
         a display name would be the wrong shape for that. --}}
    <flux:modal name="edit-profile" class="w-full max-w-md">
        <form wire:submit="saveProfile" class="flex flex-col gap-5">
            <div class="flex flex-col gap-1">
                <flux:heading size="lg">Edit profile</flux:heading>
                <flux:subheading>{{ Voice::line('profile.subheading') }}</flux:subheading>
            </div>

            <div class="flex gap-3">
                <flux:input wire:model="first_name" label="First name" class="flex-1" autocomplete="given-name" />
                <flux:input wire:model="last_name" label="Last name" class="flex-1" autocomplete="family-name" />
            </div>

            {{-- Masked on the CLIENT as well as sanitised on the server.
                 Livewire will not overwrite a focused input — that is what
                 keeps it from clobbering your typing — so a server-side clean
                 leaves the visible text disagreeing with the stored value until
                 blur. The mask corrects the character as it is typed; the
                 server rule stays as the guarantee. --}}
            <flux:input
                wire:model="handle"
                x-mask:dynamic="$input.toLowerCase().replace(/[^a-z0-9_]/g, '').slice(0, 20)"
                label="Handle"
                autocomplete="username"
                placeholder="dawgpound99"
                description="Lowercase letters, numbers and underscores."
            />

            <flux:radio.group
                wire:model="content_rating"
                label="Trash talk"
                :description="Voice::line('profile.rating_description')"
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

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" wire:loading.attr="disabled" wire:target="saveProfile" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- One card, not two. Pinning used to be its own picker over every FBS
         team, which meant it could select a team the user did not follow — so
         it had to be refused at the cap, and two searchable listboxes on one
         screen collided badly enough that picking a team to follow silently
         rewrote the pinned one. Pinning from the list the user already has
         cannot do either. --}}
    <flux:card class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="lg">Your teams</flux:heading>

                {{-- The count answers the question the cap raises — how many
                     slots are left — without spending a sentence on it. --}}
                <flux:badge size="sm" :color="$this->atLimit ? 'amber' : 'zinc'">
                    {{ $this->followed->count() }} / {{ App\Models\User::MAX_FOLLOWED_TEAMS }}
                </flux:badge>
            </div>

            <flux:subheading>{{ Voice::line('teams.subheading') }}</flux:subheading>
        </div>

        {{-- Adding a team belongs here, not only on a team page. Reaching the
             team page first means already knowing where to go; this is the
             screen someone opens when they want to change who they follow.

             Disabled rather than hidden at the cap, so the control does not
             vanish and leave the limit unexplained — and the placeholder says
             what happened and what to do about it. --}}
        <flux:input
            wire:model.live.debounce.200ms="teamSearch"
            size="sm"
            icon="magnifying-glass"
            :disabled="$this->atLimit"
            {{-- The prompt stays plain — it is an affordance, and a joke in a
                 placeholder is read every time the field is empty. The AT-LIMIT
                 message is the one talking to the user about something they
                 did, so that one speaks in their register. --}}
            :placeholder="$this->atLimit
                ? Voice::line('teams.at_limit', ['max' => App\Models\User::MAX_FOLLOWED_TEAMS])
                : 'Search teams to follow'"
            :clearable="! $this->atLimit"
        />

        @if (! $this->atLimit && trim($teamSearch) !== '')
            <div class="flex flex-col gap-1">
                @forelse ($this->matches as $match)
                    <button
                        type="button"
                        wire:click="follow({{ $match['id'] }})"
                        wire:key="match-{{ $match['id'] }}"
                        class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    >
                        <flux:icon name="plus" variant="micro" class="shrink-0 text-zinc-400" />
                        <span class="min-w-0 truncate">{{ $match['name'] }}</span>
                    </button>
                @empty
                    <p class="px-2 py-1.5 text-sm text-zinc-500">
                        {{ Voice::line('teams.no_matches', ['query' => $teamSearch]) }}
                    </p>
                @endforelse
            </div>
        @endif

        @if ($followError)
            <p class="text-micro text-amber-600 dark:text-amber-500">{{ $followError }}</p>
        @endif

    {{-- Drag to reorder. `wire:sort` is Livewire's own — it brings SortableJS
         and its 150ms shuffle, so the hand-rolled FLIP that used to animate a
         pin moving is gone with the pin.

         The value is a BARE METHOD NAME, never a call expression. Livewire
         passes the moved item and its new 0-based index itself; writing
         `reorder($item, $position)` sends NULLs instead, because
         `contextualizeExpression()` rewrites every identifier that is not in
         the element's Alpine scope to `$wire.<ident>` — and the $item/$position
         magics arrive as an evaluator OPTION, not as element scope. So the
         call became `$wire.reorder($wire.$item, $wire.$position)`, both
         undefined, and the server rejected a null team id.

         `place()` rebuilds the full order from that pair, so the drag path
         gets the same membership validation as the keyboard path. --}}
    <div wire:sort="reorder" class="flex flex-col gap-3">
        @foreach ($this->followed as $team)
            <div
                wire:sort:item="{{ $team->id }}"
                wire:key="followed-{{ $team->id }}"
                class="flex items-center gap-2 rounded-lg bg-white dark:bg-zinc-900"
            >
                {{-- The handle is what makes the ROW draggable without
                     capturing taps on the links inside it. --}}
                <span
                    wire:sort:handle
                    class="shrink-0 cursor-grab touch-none p-1 text-zinc-300 active:cursor-grabbing dark:text-zinc-600"
                    aria-hidden="true"
                >
                    <flux:icon name="bars-3" variant="micro" />
                </span>

                <span class="tabular w-4 shrink-0 text-micro font-semibold text-zinc-400">{{ $loop->iteration }}</span>

                <x-team-link :team="$team" size="sm" class="min-w-0 flex-1" />

                {{-- The keyboard path to the same outcome. A drag handle is
                     unreachable without a pointer, and the order is the only
                     way to say which team leads — so it cannot be
                     pointer-only. Hidden from pointer users at `sm` and up
                     would be worse, not better: they are small and quiet. --}}
                <flux:button
                    wire:click="move({{ $team->id }}, -1)"
                    size="sm"
                    square
                    variant="ghost"
                    class="-my-1 shrink-0"
                    :disabled="$loop->first"
                    aria-label="Move {{ $team->display_name }} up"
                    icon="chevron-up"
                />

                <flux:button
                    wire:click="move({{ $team->id }}, 1)"
                    size="sm"
                    square
                    variant="ghost"
                    class="-my-1 shrink-0"
                    :disabled="$loop->last"
                    aria-label="Move {{ $team->display_name }} down"
                    icon="chevron-down"
                />

                <flux:button
                    wire:click="unfollow({{ $team->id }})"
                    size="sm"
                    variant="ghost"
                    icon="x-mark"
                    class="-my-1 shrink-0"
                    aria-label="Unfollow {{ $team->display_name }}"
                />
            </div>
        @endforeach

        @if ($this->followed->isEmpty())
            <flux:text class="text-sm text-zinc-500">
                {{ Voice::line('teams.empty') }}
            </flux:text>
        @endif
    </div>
    </flux:card>

    {{--
        Admin and sign-out live here, not only in the desktop avatar menu.
        The header is hidden below `sm`, so anything reachable only from that
        dropdown would be unreachable on a phone — which is exactly the failure
        this navigation rework exists to remove.
    --}}
    {{-- Spans both columns: a row of four ghost buttons in a half-width cell
         leaves the other half empty at the very bottom of the screen. --}}
    <flux:card class="flex flex-col gap-2 lg:col-span-2">
        {{-- Hidden by stylesheet inside the installed app — a "Get the app"
             row in the app is furniture pointing at itself. --}}
        <flux:button
            :href="route('get-app')"
            wire:navigate
            size="sm"
            variant="ghost"
            class="justify-start"
            data-install-only
        >
            <flux:icon.phone variant="micro" />
            Get the app
        </flux:button>

        {{-- A plain link: ?tour=1 makes Home mount the tour regardless of the
             completed stamp, so a replay is also shareable in a bug report. --}}
        <flux:button
            :href="route('home', ['tour' => 1])"
            wire:navigate
            size="sm"
            variant="ghost"
            class="justify-start"
        >
            <flux:icon.arrow-repeat variant="micro" />
            Replay the tour
        </flux:button>

        {{-- The SECOND walk, beside the first and never folded into it: the
             app's first-run story and the economy's are different halves,
             and a reader who wants the Tallboy rules again should not have
             to sit through the install pitch to reach them. Same `?tour=1`
             grammar on a different screen — one verb to remember. Behind
             the pick'em flag, because outside it Picks is a promise and
             there is nothing to walk. --}}
        @if (Laravel\Pennant\Feature::active('pickem'))
            <flux:button
                :href="route('pickem.home', ['tour' => 1])"
                wire:navigate
                size="sm"
                variant="ghost"
                class="justify-start"
            >
                <flux:icon.arrow-repeat variant="micro" />
                Replay the Picks tour
            </flux:button>
        @endif

        {{-- The feedback door. A trigger around a plain button: the sheet
             itself is mounted once by the layout, so this row owns nothing
             but the tap. --}}
        <flux:modal.trigger name="help">
            <flux:button size="sm" variant="ghost" class="justify-start">
                <flux:icon.chat-dots variant="micro" />
                {{ App\Support\HelpAnswer::doorLabel(auth()->user()) }}
            </flux:button>
        </flux:modal.trigger>

        @if (auth()->user()->isAdmin())
            <flux:button href="/admin" icon="wrench-screwdriver" size="sm" variant="ghost" class="justify-start">
                Admin
            </flux:button>
        @endif

        <flux:button
            type="submit"
            form="logout-form-account"
            icon="arrow-right-start-on-rectangle"
            size="sm"
            variant="ghost"
            class="justify-start"
        >
            Log out
        </flux:button>

        <form id="logout-form-account" method="POST" action="{{ route('logout') }}" class="hidden">
            @csrf
        </form>
    </flux:card>

    {{-- The release stamp, last and quiet, spanning both columns like the
         card above it. Below `sm` this is the ONLY place it shows: the desktop
         avatar menu carries the same line, and does not exist on a phone.
         Nothing renders without a stamp — Release never invents one. --}}
    @php $release = App\Support\Release::tag(); @endphp
    @if ($release !== null)
        <p class="text-center text-micro text-zinc-400 lg:col-span-2 dark:text-zinc-500" data-release>
            {{ App\Support\Brand::name() }} {{ $release }}
        </p>
    @endif
</div>
