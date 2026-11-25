<?php

namespace App\Filament\Resources\CardSets\Pages;

use App\Filament\Resources\CardSets\CardSetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCardSet extends CreateRecord
{
    protected static string $resource = CardSetResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
