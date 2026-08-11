{{--
    Where the footer link lands. Uses the AUTH layout rather than the app one:
    the reader is almost certainly signed out, and dropping them onto a page
    with a bottom tab bar and a scoreboard rail invites them to wander off
    without ever seeing that the thing they asked for actually happened.
--}}
<x-layouts.auth :title="'Unsubscribed — '.App\Support\Brand::name()">
    <div class="flex flex-col gap-4 text-center">
        <flux:heading size="lg">Done.</flux:heading>

        <flux:text>{{ $message }}</flux:text>

        {{-- The way back. Somebody who unsubscribed by accident — or from a
             one-click they did not mean to press — needs an obvious undo, and
             the setting lives on Account. --}}
        <flux:text class="text-sm">
            Changed your mind? Turn it back on any time from
            <a href="{{ route('account') }}" wire:navigate class="underline">your account</a>.
        </flux:text>

        {{-- Said plainly, because it is the question this page raises: people
             assume unsubscribing breaks their login. --}}
        <flux:text class="text-sm text-zinc-500">
            Your account is untouched, and you will still get password resets
            and anything else you ask us for.
        </flux:text>
    </div>
</x-layouts.auth>
