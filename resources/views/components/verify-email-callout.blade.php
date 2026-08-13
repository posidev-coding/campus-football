{{--
    The verify-your-email nudge, shown only to signed-in, unverified accounts.
    ONE slim row, deliberately: it rides above Home's real content, and a
    stacked card there taxed the screen it was trying to improve. The single
    line is reward-first — the Beast Latte sells, the fine print stays in the
    mail — and the resend affordance stays plain beside it.

    Dismissal is $persist to SESSIONSTORAGE, deliberately weaker than the
    install banner's localStorage: the nudge must return next visit because
    the 14-day self-destruct clock is real, while within one sitting a
    dismissal holds — and because it is client state, no Livewire morph can
    resurrect it mid-page.

    The picks screen reuses this with :body-key="'verify.picks.body'" and
    :dismissable="false" — there it explains a gate, and an explanation you
    can dismiss becomes a mystery.
--}}
@props(['bodyKey' => 'verify.callout.body', 'dismissable' => true])

@if (auth()->check() && ! auth()->user()->hasVerifiedEmail())
    <div
        @if ($dismissable)
            x-data="{ dismissed: $persist(false).using(sessionStorage).as('cfb.verify.dismissed') }"
            x-show="! dismissed"
            x-cloak
        @endif
        data-verify-callout
        {{ $attributes->class([
            'flex items-center gap-2.5 rounded-xl bg-amber-50 py-2 pl-3 ring-1 ring-amber-200',
            'dark:bg-amber-950/30 dark:ring-amber-900',
            $dismissable ? 'pr-1' : 'pr-3',
        ]) }}
    >
        <flux:icon.envelope class="size-4 shrink-0 text-amber-600 dark:text-amber-500" />

        <p class="min-w-0 flex-1 text-sm text-zinc-700 dark:text-zinc-300">
            {{ App\Support\Voice::line($bodyKey) }}

            {{-- Plain affordance: the label never jokes, and the notice
                 screen it opens owns the actual resend button. --}}
            <flux:link :href="route('verification.notice')" wire:navigate class="text-sm whitespace-nowrap">
                Resend
            </flux:link>
        </p>

        @if ($dismissable)
            <flux:button
                x-on:click="dismissed = true"
                size="xs"
                square
                variant="ghost"
                icon="x-mark"
                class="shrink-0"
                aria-label="Dismiss"
            />
        @endif
    </div>
@endif
