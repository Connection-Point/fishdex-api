<?php

namespace App\Filament\Resources\CardSets\RelationManagers;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\ReferenceImage;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CardsRelationManager extends RelationManager
{
    protected static string $relationship = 'cards';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('species_id')->label('Species')->required()->searchable()
                ->options(fn () => FishSpecies::query()->orderBy('scientific_name')->pluck('scientific_name', 'id')),
            TextInput::make('card_number')->required()->maxLength(10),
            Select::make('variant')->options(['standard' => 'Standard', 'holo' => 'Holo', 'alt_art' => 'Alternate art', 'event' => 'Event'])->default('standard')->required(),
            Select::make('artwork_image_id')->label('Artwork')
                ->options(fn ($get) => ReferenceImage::query()->where('species_id', $get('species_id'))->where('is_verified', true)
                    ->get()->mapWithKeys(fn ($i) => [$i->id => "{$i->kind} — {$i->license} — {$i->public_id}"]))
                ->helperText('Verified images of the chosen species.'),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('card_number')
            ->columns([
                TextColumn::make('card_number')->sortable(),
                TextColumn::make('species.scientific_name')->label('Species')->searchable(),
                TextColumn::make('variant')->badge(),
                IconColumn::make('artwork_image_id')->label('Art')->boolean(fn ($state) => $state !== null),
                IconColumn::make('is_active')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->after(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->forceFill([
                    'total_cards' => $livewire->getOwnerRecord()->cards()->where('variant', 'standard')->count(),
                ])->save()),
            ])
            ->recordActions([EditAction::make()]);
    }
}
