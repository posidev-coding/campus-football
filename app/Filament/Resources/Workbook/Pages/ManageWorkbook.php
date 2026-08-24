<?php

namespace App\Filament\Resources\Workbook\Pages;

use App\Filament\Resources\Workbook\WorkbookResource;
use App\Models\WorkbookItem;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

/**
 * One page, modals for the rest — the ManageTeams shape. A workbook item is
 * small enough that a dedicated create screen would be more navigation than
 * content.
 */
class ManageWorkbook extends ManageRecords
{
    protected static string $resource = WorkbookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A human files here too. The advisor is the volume, not the
            // authority — `source` says which is which.
            CreateAction::make()
                ->label('File an item')
                ->mutateDataUsing(function (array $data): array {
                    $data['key'] = 'human-'.str()->slug(mb_substr($data['title'], 0, 60)).'-'.now()->format('ymdHis');
                    $data['source'] = WorkbookItem::SOURCE_HUMAN;
                    $data['first_seen_at'] = now();
                    $data['last_seen_at'] = now();

                    return $data;
                }),
        ];
    }
}
