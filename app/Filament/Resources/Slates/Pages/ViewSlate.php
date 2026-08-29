<?php

namespace App\Filament\Resources\Slates\Pages;

use App\Filament\Resources\Slates\SlateResource;
use App\Filament\Resources\Slates\Widgets\SlateStats;
use App\Models\Slate;
use App\Support\Cadence;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewSlate extends ViewRecord
{
    protected static string $resource = SlateResource::class;

    public function getHeading(): Htmlable
    {
        /** @var Slate $record */
        $record = $this->getRecord();

        // The deadline resolves against this slate's OWN Saturday, in Eastern
        // wall time — never `now()`, and never UTC.
        $deadline = Cadence::slateDeadline($record->saturday);

        return view('filament.partials.record-heading', [
            'title' => $record->saturday?->format('F j, Y') ?? 'Slate',
            'badges' => array_values(array_filter([
                [
                    'label' => SlateResource::statusLabel($record->status),
                    'color' => SlateResource::statusColor($record->status),
                ],
                $record->contest === null
                    ? null
                    : ['label' => $record->contest->mode->label(), 'color' => 'gray'],
                $record->exhibition ? ['label' => 'Exhibition', 'color' => 'warning'] : null,
                $record->bear_theme === null ? null : ['label' => $record->bear_theme, 'color' => 'danger'],
            ])),
            'meta' => array_values(array_filter([
                $record->contest?->group === null
                    ? null
                    : ['icon' => 'heroicon-o-user-group', 'label' => $record->contest->group->name],
                $record->week === null
                    ? null
                    : ['icon' => 'heroicon-o-hashtag', 'label' => 'Week '.$record->week->number],
                $deadline === null
                    ? null
                    : ['icon' => 'heroicon-o-clock', 'label' => 'Picks due '.$deadline->format('D g:i a T')],
                $record->published_at === null
                    ? ['icon' => 'heroicon-o-eye-slash', 'label' => 'Not published']
                    : ['icon' => 'heroicon-o-eye', 'label' => 'Published '.$record->published_at->format('M j')],
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
            SlateStats::make(['record' => $this->getRecord()]),
        ];
    }

    protected function getHeaderActions(): array
    {
        // Read-only. Publishing, lining and settling are Actions with rules a
        // form cannot carry — see SlateResource's docblock.
        return [];
    }
}
