<?php

namespace App\Filament\Resources\FishSpecies\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SynonymsRelationManager extends RelationManager
{
    protected static string $relationship = 'synonyms';

    protected static ?string $title = 'Scientific synonyms';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('scientific_name')->required()->maxLength(160)->unique(ignoreRecord: true)
                ->helperText('Older or alternative scientific names the AI might return.'),
            Select::make('kind')->options(['synonym' => 'Synonym', 'former_name' => 'Former name', 'misspelling' => 'Common misspelling'])->required()->default('synonym'),
            TextInput::make('source')->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('scientific_name')
            ->columns([
                TextColumn::make('scientific_name'),
                TextColumn::make('kind')->badge(),
                TextColumn::make('source')->limit(40),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
