{{--
    The push nudge — the install banner's sibling for INSIDE the installed
    app, which is why it can never stack with it: that one wears
    data-install-only, this one wears data-standalone-only, and the two are
    disjoint by stylesheet. Same slim row, same per-user-per-device
    localStorage dismissal, same demonstrated-interest gate (the caller
    wraps it in hasToured()).

    Renders only while the permission is genuinely askable: granted needs
    no pitch, denied cannot prompt (the switch on Account owns that
    story), unsupported has nothing to offer. The tap runs the prompt
    inside the gesture, and on success the row swaps to its confirmation —
    which the welcome push makes literally true.
--}}
@auth
    <div
        data-push-banner
        data-standalone-only
        x-cloak
        x-data="{
            dismissed: $persist(false).as('cfb.push.dismissed.' + {{ auth()->id() }}),
            askable: false,
            confirmed: false,
            busy: false,

            init() {
                this.askable = window.cfbPush.supported() && window.cfbPush.permission() === 'default';
            },

            async turnOn() {
                if (this.busy) return;

                this.busy = true;

                let result = await window.cfbPush.enable(
                    @js(config('webpush.vapid.public_key')),
                    @js(route('push.store')),
                );

                this.busy = false;

                if (result === 'granted') {
                    this.confirmed = true;
                } else if (result === 'denied') {
                    this.askable = false;
                }
            },
        }"
        x-show="! dismissed && (askable || confirmed)"
        {{ $attributes->class([
            'flex items-center gap-3 rounded-xl bg-zinc-50 px-4 py-2.5 ring-1 ring-zinc-200',
            'dark:bg-zinc-900 dark:ring-zinc-800',
        ]) }}
    >
        <flux:icon.bell class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400" variant="mini" />

        <p x-show="! confirmed" class="min-w-0 flex-1 truncate text-sm">
            <span class="font-medium">{{ App\Support\Voice::line('push.banner.heading') }}</span>
            <span class="hidden text-zinc-500 sm:inline dark:text-zinc-400">{{ App\Support\Voice::line('push.banner.body') }}</span>
        </p>

        <p x-cloak x-show="confirmed" class="min-w-0 flex-1 truncate text-sm">
            {{ App\Support\Voice::line('push.banner.confirmed') }}
        </p>

        <flux:button x-show="! confirmed" x-on:click="turnOn()" x-bind:disabled="busy" variant="filled" size="xs" class="shrink-0">
            Turn on
        </flux:button>

        <flux:button x-on:click="dismissed = true" size="xs" square variant="ghost" icon="x-mark" class="shrink-0" aria-label="Dismiss" />
    </div>
@endauth
