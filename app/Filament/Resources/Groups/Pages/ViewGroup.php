<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Groups\Widgets\GroupStats;
use App\Models\Group;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

class ViewGroup extends ViewRecord
{
    protected static string $resource = GroupResource::class;

    public function getHeading(): Htmlable
    {
        /** @var Group $record */
        $record = $this->getRecord();

        return view('filament.partials.record-heading', [
            'initials' => Str::of($record->name)->explode(' ')->take(2)
                ->map(fn (string $word): string => Str::substr($word, 0, 1))->implode(''),
            'title' => $record->name,
            'badges' => array_values(array_filter([
                [
                    'label' => $record->isLobby() ? 'Lobby room' : 'Private group',
                    'color' => $record->isLobby() ? 'gray' : 'info',
                ],
                $record->flavorEnum() === null
                    ? null
                    : ['label' => $record->flavorEnum()->label(), 'color' => 'warning'],
                $record->filled_at === null ? null : ['label' => 'Full', 'color' => 'danger'],
            ])),
            'meta' => array_values(array_filter([
                ['icon' => 'heroicon-o-key', 'label' => $record->code],
                $record->member_cap === null
                    ? ['icon' => 'heroicon-o-users', 'label' => 'Uncapped']
                    : ['icon' => 'heroicon-o-users', 'label' => $record->member_cap.' seats'],
                ['icon' => 'heroicon-o-calendar', 'label' => 'Created '.$record->created_at?->format('M j, Y')],
            ])),
        ]);
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            GroupStats::make(['record' => $this->getRecord()]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
