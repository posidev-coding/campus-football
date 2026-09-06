@php
    use App\Models\ActivityEvent;
    use App\Support\Brand;
    use App\Support\PageMeta;

    /*
     * WHAT THE APP RECORDS — the whole of it, in the order somebody worried
     * would ask, and in plain sentences.
     *
     * ONE REGISTER, NOT THREE. Every other screen writes PG, PG-13 and R,
     * because voice is a product requirement here. This one does not, for two
     * reasons that both point the same way: it renders for a signed-out
     * reader, who has no content rating for a register to be chosen from, and
     * a statement about somebody's data that gets funnier as they turn the
     * dial up is not a statement they can rely on. The offline page made the
     * same call for its own reasons.
     *
     * NOTHING HERE IS RESTATED FROM MEMORY. The thirty days is
     * ActivityEvent::KEEP_DAYS, read from the constant the pruner itself uses,
     * so the promise on this page and the job that keeps it cannot drift —
     * PrivacyTest asserts the rendered number IS the constant.
     *
     * A VISIBLE HEADING, unlike every other screen. The rule is that the
     * section strip already names the screen, so an `h1` would say the same
     * word twice — and this page is deliberately outside every nav area, so
     * there is no strip to name it. Scores is the other exception, for the
     * same reason.
     */
    app(PageMeta::class)->set(
        title: 'What we record',
        description: 'Which screens the app counts, what it keeps, and how long it keeps it.',
    );
@endphp

<x-layouts.app>
    <div class="flex flex-col gap-6 md:mx-auto md:w-full md:max-w-2xl">
        <div class="flex flex-col gap-1">
            <h1 class="text-2xl font-bold tracking-tight">What we record</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ Brand::name() }} counts how the app is used so it can be made better. Here is all of it.
            </p>
        </div>

        {{-- Each section is a claim about the code, and each claim is one a
             reader could check by watching the network tab. --}}
        <section class="flex flex-col gap-2">
            <h2 class="font-semibold">The screens you open</h2>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                When you open a screen, the app records the screen's <em>name</em> — "scoreboard",
                "pickem.group" — and never the address. That distinction is the point: an address
                carries the group you were in, the invite code you followed and anything else in
                the link. The name carries none of it, and there is no list of addresses anywhere
                to go back to.
            </p>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Nothing you type is recorded this way. What you search for, what you write in Talk
                and what you send as feedback are not part of this — a note you send is a note you
                chose to send.
            </p>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="font-semibold">Your device, twice</h2>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Two things about the device, both of which your browser volunteers to every website
                you visit: how wide the screen is, and whether you are reading in a browser tab or
                from an app you added to your home screen. The width is how we find a layout that
                breaks at a size we do not own. Neither one identifies a device, and we do not
                store anything that would.
            </p>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="font-semibold">What is kept, and for how long</h2>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                The detailed log — the one row per screen you opened, which is the only part
                attached to your account — is deleted after
                <strong>{{ ActivityEvent::KEEP_DAYS }} days</strong>. It exists that long so a
                broken screen can be read against the traffic that hit it, and then it goes.
            </p>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                What is kept is the counting: how many screens were read on a given day, and which
                days you were here. Those are per-day totals, and they are what any question about
                whether the app is working gets answered from.
            </p>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="font-semibold">Deleting your account</h2>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                All of it goes with you — the log, the per-day rows, your picks, your groups and
                your ledger. Not anonymized, not held back for counting. Deleted.
            </p>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="font-semibold">What we do not do</h2>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                No advertising, no advertising networks, no selling any of this to anybody, and no
                third-party analytics service — the counting is done by this app, in its own
                database, and it goes nowhere else.
            </p>
        </section>

        {{-- The way back, at the foot rather than as a band at the top: this
             page is reached from Account and from the register form, and both
             of those are somewhere a reader wants to return to. --}}
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            <flux:link :href="route('home')" wire:navigate>Back to {{ Brand::shortName() }}</flux:link>
        </p>
    </div>
</x-layouts.app>
