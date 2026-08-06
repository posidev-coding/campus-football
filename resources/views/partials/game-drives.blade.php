{{-- The full drive chart, chronological — reading the game top to bottom. --}}
<div class="flex flex-col gap-2">
    @if ($this->drives !== [])
        <x-drive-list :game="$game" :drives="$this->drives" />
    @else
        <flux:callout icon="chart-bar">
            <flux:callout.heading>No drives yet</flux:callout.heading>
            <flux:callout.text>The drive chart appears once the box score lands.</flux:callout.text>
        </flux:callout>
    @endif
</div>
