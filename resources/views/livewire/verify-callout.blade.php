<?php

use Livewire\Component;

/**
 * The verify-your-email nudge — its own tiny component so the ambient
 * poll re-renders THIS row alone. As a Blade component its wire:poll
 * bound to the host Livewire root: the entire screen re-rendered
 * (Home ≈ 12-18 queries) four times a minute for every unverified
 * reader, across seven hosts.
 *
 * Two couplings survive the islanding, both deliberate:
 *
 *  (a) The verified FLIP must refresh the HOST once — the wallet chips
 *      live in the host's tree, and the flip is the moment they change
 *      (100 XP + 1 Tallboy). check() dispatches `email-verified`; every
 *      embed carries `@email-verified="$refresh"` so the host re-renders
 *      exactly once, on the flip rather than on every tick.
 *
 *  (b) `.visible` stays OFF the poll — a deliberate deviation from the
 *      house wire:poll shape: dismissal display:nones the row via
 *      x-show, and the flip must still reach a reader who waved the
 *      nudge away. The @if guard IS the "something to poll": the row,
 *      its poll and this component's work all cease on the verified
 *      render.
 */
new class extends Component
{
    public string $bodyKey = 'verify.callout.body';

    public bool $dismissable = true;

    /** Extra classes for the row — Account spans its grid with this. */
    public string $class = '';

    public function check(): void
    {
        if (auth()->user()?->hasVerifiedEmail()) {
            $this->dispatch('email-verified');
        }
    }
}; ?>

{{--
    The root is UNCONDITIONAL and `contents` on purpose: Livewire records a
    child's tag from the first `<` of its html, so a root born inside an
    @if would put a morph marker first and corrupt the record — and
    display:contents keeps this wrapper from claiming a grid cell or a
    stack gap when the row inside it is not rendered. The row is the real
    layout box, which is why $class lands there.
--}}
<div class="contents">
    @if (auth()->check() && ! auth()->user()->hasVerifiedEmail())
        {{--
            One slim row, reward-first — the Tallboy sells, the fine
            print stays in the mail. Dismissal is $persist to
            SESSIONSTORAGE, deliberately weaker than the install banner's
            localStorage: the nudge must return next visit because the
            14-day self-destruct clock is real, while within one sitting a
            dismissal holds — and because it is client state, no Livewire
            morph can resurrect it.

            The picks screens reuse this with :body-key="'verify.picks.body'"
            and :dismissable="false" — there it explains a gate, and an
            explanation you can dismiss becomes a mystery.

            30s: the moment being caught is "clicked the link in another
            tab, came back" — a launch-length wait, not a scoreboard.
        --}}
        <div
            wire:poll.30s="check"
            {{--
                The scope is UNCONDITIONAL and the @if decides only who READS
                it. Attaching x-data inside the conditional gave ONE Livewire
                component two different Alpine scopes keyed by a prop: Home
                and Account defined `dismissed`, the five picks surfaces
                defined nothing at all, and `x-show="! dismissed"` plus the
                dismiss button's `dismissed = true` read a variable that
                existed in only one of them.

                Alpine reports that as a bare `ReferenceError: dismissed is
                not defined` from its evaluator — Safari phrases it "Can't
                find variable" — carrying no element and no file, which is how
                one landed on /verify-email, a screen with no callout on it.
                Scope first, behavior conditional; `AlpineExpressionsTest`
                sweeps for an x-data attached inside a Blade conditional.
            --}}
            x-data="{ dismissed: $persist(false).using(sessionStorage).as('cfb.verify.dismissed') }"
            @if ($dismissable)
                x-show="! dismissed"
                x-cloak
            @endif
            data-verify-callout
            class="{{ trim('flex items-center gap-2.5 rounded-xl bg-amber-50 py-2 pl-3 ring-1 ring-amber-200 dark:bg-amber-950/30 dark:ring-amber-900 '.($dismissable ? 'pr-1' : 'pr-3').' '.$class) }}"
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
</div>
