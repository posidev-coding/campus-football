<x-layouts.app title="Account">
    <div class="flex flex-col gap-6">
        <flux:heading size="xl">Account</flux:heading>

        <flux:card class="flex flex-col gap-3">
            <div class="flex items-center gap-3">
                <flux:avatar :initials="auth()->user()->initials()" />
                <div class="flex flex-col">
                    <span class="font-medium">{{ auth()->user()->name }}</span>
                    <span class="text-sm text-zinc-500">{{ auth()->user()->email }}</span>
                </div>
            </div>

            <flux:separator />

            <div class="flex items-center justify-between text-sm">
                <span class="text-zinc-500">Trash talk</span>
                <flux:badge size="sm">{{ auth()->user()->trash_talk_intensity->label() }}</flux:badge>
            </div>
        </flux:card>
    </div>
</x-layouts.app>
