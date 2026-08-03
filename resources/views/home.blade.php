<x-layouts.app :title="config('app.name')">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-2">
            <flux:heading size="xl">Campus Football</flux:heading>
            <flux:subheading>
                Every game, every team, every player — and a pick'em your group will actually argue about.
            </flux:subheading>
        </div>

        @guest
            <flux:card class="flex flex-col gap-4">
                <div class="flex flex-col gap-1">
                    <flux:heading size="lg">Ready to play?</flux:heading>
                    <flux:text>Create an account, start a group, and set your first slate.</flux:text>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row">
                    <flux:button :href="route('register')" wire:navigate variant="primary" class="w-full sm:w-auto">
                        Create an account
                    </flux:button>
                    <flux:button :href="route('login')" wire:navigate variant="ghost" class="w-full sm:w-auto">
                        Log in
                    </flux:button>
                </div>
            </flux:card>
        @endguest

        @auth
            <flux:callout icon="wrench-screwdriver">
                <flux:callout.heading>Foundation is in place</flux:callout.heading>
                <flux:callout.text>
                    Sports data, rosters, and the pick'em land in the phases ahead.
                </flux:callout.text>
            </flux:callout>
        @endauth
    </div>
</x-layouts.app>
