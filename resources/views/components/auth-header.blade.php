@props([
    'title',
    'description' => null,
])

<div class="flex flex-col gap-1 text-center">
    <flux:heading size="lg">{{ $title }}</flux:heading>

    @if ($description)
        <flux:subheading>{{ $description }}</flux:subheading>
    @endif
</div>
