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

<div class="flex flex-col gap-4 lg:mx-auto lg:w-full lg:max-w-2xl">
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

    @if ($this->rows !== [])
        <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 text-micro text-zinc-500 dark:border-zinc-800/60">
                        <th scope="col" class="w-8 px-3 py-1.5 text-left font-medium">#</th>
                        <th scope="col" class="py-1.5 pe-3 text-left font-medium">Name</th>
                        <th scope="col" class="whitespace-nowrap py-1.5 pe-3 text-right font-medium">XP</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->rows as $row)
                        @php $viewer = $row['user_id'] === auth()->id(); @endphp
                        <tr
                            wire:key="lb-{{ $row['user_id'] }}"
                            @class([
                                'border-b border-zinc-50 last:border-0 dark:border-zinc-800/40',
                                'bg-blue-50/60 dark:bg-blue-950/30' => $viewer,
                            ])
                        >
                            <td class="tabular whitespace-nowrap px-3 py-1.5 text-zinc-500">{{ $row['rank'] }}</td>
                            <td class="w-full max-w-0 truncate py-1.5 pe-3 {{ $viewer ? 'font-semibold' : 'font-medium' }}">{{ $row['label'] }}</td>
                            <td class="tabular whitespace-nowrap py-1.5 pe-3 text-right font-semibold">{{ number_format($row['xp']) }}</td>
                        </tr>
                    @endforeach

                    {{-- The viewer, pinned when their seat is below the fold. --}}
                    @if ($this->mine !== null && $this->mine['rank'] > count($this->rows))
                        <tr class="border-t-2 border-zinc-200 bg-blue-50/60 dark:border-zinc-700 dark:bg-blue-950/30">
                            <td class="tabular whitespace-nowrap px-3 py-1.5 text-zinc-500">{{ $this->mine['rank'] }}</td>
                            <td class="w-full max-w-0 truncate py-1.5 pe-3 font-semibold">You</td>
                            <td class="tabular whitespace-nowrap py-1.5 pe-3 text-right font-semibold">{{ number_format($this->mine['xp']) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
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
