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
            //
            // `using()` rather than `mutateDataUsing()`, because the board can
            // file as well and the key, the source and the end-of-column
            // position are the resource's to decide once.
            CreateAction::make()
                ->label('File an item')
                ->using(fn (array $data): WorkbookItem => WorkbookResource::fileAsHuman($data)),
        ];
    }
}
