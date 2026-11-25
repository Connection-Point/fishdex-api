<?php

namespace App\Filament\Resources\FishSpecies\Pages;

use App\Filament\Resources\FishSpecies\FishSpeciesResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditFishSpecies extends EditRecord
{
    protected static string $resource = FishSpeciesResource::class;

    protected function getHeaderActions(): array
    {
        // Soft delete only: species with encounters must never be hard-deleted.
        return [DeleteAction::make(), RestoreAction::make()];
    }
}
