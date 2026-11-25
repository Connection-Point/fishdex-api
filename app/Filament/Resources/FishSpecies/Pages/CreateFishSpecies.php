<?php

namespace App\Filament\Resources\FishSpecies\Pages;

use App\Filament\Resources\FishSpecies\FishSpeciesResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFishSpecies extends CreateRecord
{
    protected static string $resource = FishSpeciesResource::class;

    protected function getRedirectUrl(): string
    {
        // Go straight to edit so names, rarity and images can be added.
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
