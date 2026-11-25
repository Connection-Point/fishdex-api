<?php

namespace App\Filament\Resources\FishSpecies\RelationManagers;

use App\Domain\Catalogue\Models\Country;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CountryStatusesRelationManager extends RelationManager
{
    protected static string $relationship = 'countryStatuses';

    protected static ?string $title = 'Origin & legal status by country';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('country_id')->label('Country')->options(fn () => Country::query()->pluck('name', 'id'))->required(),
            Select::make('origin')->options(['native' => 'Native', 'endemic' => 'Endemic', 'introduced' => 'Introduced', 'invasive' => 'Invasive', 'uncertain' => 'Uncertain'])->required(),
            Toggle::make('is_present')->default(true),
            Select::make('legal_protection')->options(['none' => 'None', 'protected' => 'Protected', 'restricted' => 'Restricted (size/season limits)', 'sanctuary_only' => 'Protected in sanctuaries'])->default('none')->required(),
            TextInput::make('legal_notes')->maxLength(500)->helperText('Shown to anglers after confirming this species.'),
            Textarea::make('distribution_notes'),
            TextInput::make('source')->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('country.name'),
                TextColumn::make('origin')->badge(),
                IconColumn::make('is_present')->boolean(),
                TextColumn::make('legal_protection')->badge()->color(fn (string $state) => $state === 'none' ? 'gray' : 'danger'),
                TextColumn::make('legal_notes')->limit(50),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
