<?php

use App\Models\GroupMember;
use App\Support\Leaderboard;
use App\Support\Voice;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * THE XP LEADERBOARD — the pick'em area's long game. Two dials: WHOSE
 * table (My groups — the people your trash talk actually reaches — or
 * Everyone) and WHICH window (This Week / This Season / All-Time, the
 * week ending at official-final so Monday shows the week that just PAID).
 *
 * The viewer's own row pins to the bottom when they fall off the page —
 * a leaderboard you cannot find yourself on is a poster, not a product.
 */
new class extends Component
{
    #[Url(except: 'groups')]
    public string $scope = 'groups';

    #[Url(except: 'week')]
    public string $view = 'week';

    public function mount(): void
    {
        $this->scope = $this->normalizedScope($this->scope);
        $this->view = in_array($this->view, Leaderboard::WINDOWS, true) ? $this->view : 'week';

        // "My groups" needs groups: a groupless reader lands on Everyone.
        if ($this->scope === 'groups' && ! GroupMember::query()->where('user_id', auth()->id())->exists()) {
            $this->scope = 'everyone';
        }
    }

    public function updatedScope(string $value): void
    {
        $this->scope = $this->normalizedScope($value);
    }

    public function updatedView(string $value): void
    {
        $this->view = in_array($value, Leaderboard::WINDOWS, true) ? $value : 'week';
    }

    /** @return list<array{rank: int, user_id: int, label: string, xp: int}> */
    #[Computed]
    public function rows(): array
    {
        return Leaderboard::top($this->view, $this->scope, auth()->user());
    }

    /** @return array{rank: int, xp: int}|null */
    #[Computed]
    public function mine(): ?array
    {
        return Leaderboard::rankOf(auth()->user(), $this->view, $this->scope);
    }

    private function normalizedScope(string $scope): string
    {
        return in_array($scope, Leaderboard::CIRCLES, true) ? $scope : 'groups';
    }
}; ?>

<div class="flex flex-col gap-4 md:mx-auto md:w-full md:max-w-3xl">
    <h1 class="sr-only">Leaderboard</h1>

    <div class="flex items-center justify-between gap-3">
        <x-gutter-tabs
            :items="['groups' => 'My groups', 'everyone' => 'Everyone']"
            :selected="$scope"
            model="scope"
            label="Whose leaderboard"
            key-prefix="lb-scope"
        />

        <x-filter-menu
            :items="[
                ['value' => 'week', 'label' => 'This Week'],
                ['value' => 'season', 'label' => 'This Season'],
                ['value' => 'all', 'label' => 'All-Time'],
            ]"
            :selected="$view"
            model="view"
            label="Time window"
            key-prefix="lb-window"
        />
    </div>

    <div
        wire:loading.class="opacity-60 pointer-events-none"
        wire:target="scope, view"
        class="flex flex-col gap-4 motion-safe:transition-opacity"
    >
    @if ($this->rows !== [])
        {{-- The SAME table the clubhouse ranks a week with — one ranked-
             rows vocabulary, so the leaderboard cannot drift from the room.
             Labels are precomputed strings (rows come ranked from
             Leaderboard), so `viewer` rides the row rather than a User. --}}
        @php
            $tableRows = collect($this->rows)->map(fn (array $row) => [
                'rank' => $row['rank'],
                'user' => null,
                'label' => $row['label'],
                'key' => 'lb-'.$row['user_id'],
                'viewer' => $row['user_id'] === auth()->id(),
                'cells' => [number_format($row['xp'])],
            ])->all();

            $windowTitle = ['week' => 'This Week', 'season' => 'This Season', 'all' => 'All-Time'][$view] ?? 'Leaderboard';
        @endphp

        <x-standings-table
            :rows="$tableRows"
            :headings="['XP']"
            :title="$windowTitle"
            :pinned="$this->mine !== null && $this->mine['rank'] > count($this->rows)
                ? ['rank' => $this->mine['rank'], 'label' => 'You', 'cells' => [number_format($this->mine['xp'])]]
                : null"
        />
    @else
        {{-- A door, not a dead end: the reader with no standings is
             exactly the reader a lobby seat would fix. --}}
        <x-empty-state icon="trophy" heading="No standings yet" :body="Voice::line('leaderboard.empty')">
            <flux:button :href="route('pickem.lobby')" wire:navigate size="sm" variant="primary">
                Find a room
            </flux:button>
        </x-empty-state>
    @endif
</div>
</div>
