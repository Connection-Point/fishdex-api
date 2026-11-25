<?php

namespace App\Filament\Resources\TaxonFamilies\Pages;

use App\Filament\Resources\TaxonFamilies\TaxonFamilyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTaxonFamilies extends ManageRecords
{
    protected static string $resource = TaxonFamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
