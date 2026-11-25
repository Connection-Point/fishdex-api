<?php

namespace App\Filament\Resources\FishSpecies\RelationManagers;

use App\Domain\Catalogue\Models\Country;
use App\Domain\Catalogue\Models\Region;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NamesRelationManager extends RelationManager
{
    protected static string $relationship = 'names';

    protected static ?string $title = 'Common & local names';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(160),
            Select::make('locale')->options(['en' => 'English', 'ms' => 'Bahasa Malaysia', 'zh' => 'Chinese', 'ta' => 'Tamil', 'id' => 'Bahasa Indonesia', 'th' => 'Thai'])->required()->default('ms'),
            Select::make('name_type')->options(['common' => 'Common', 'local_alias' => 'Local alias', 'trade' => 'Trade / market', 'juvenile' => 'Juvenile'])->required()->default('common'),
            Select::make('country_id')->label('Country (optional)')
                ->options(fn () => Country::query()->pluck('name', 'id')),
            Select::make('region_id')->label('State (dialect name, optional)')
                ->options(fn () => Region::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
            Toggle::make('is_primary')->helperText('One primary name per language is shown on the card.'),
            Toggle::make('is_ambiguous')->helperText('Name is used for several species (e.g. "kerapu"). AI matching will never resolve on it alone.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('locale')->badge(),
                TextColumn::make('name_type')->badge()->color('gray'),
                IconColumn::make('is_primary')->boolean(),
                IconColumn::make('is_ambiguous')->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
