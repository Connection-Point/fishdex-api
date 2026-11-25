<?php

namespace App\Filament\Resources\FishSpecies\RelationManagers;

use App\Domain\Catalogue\Models\Country;
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

/** Scientific/legal conservation status — informational only, separate from rarity. */
class ConservationAssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'conservationAssessments';

    protected static ?string $title = 'Conservation status';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('authority')->options(['iucn' => 'IUCN Red List (global)', 'national' => 'National', 'state' => 'State'])->required()->live(),
            Select::make('country_id')->label('Country')->options(fn () => Country::query()->pluck('name', 'id'))
                ->visible(fn ($get) => $get('authority') !== 'iucn'),
            Select::make('status_code')->label('Status')->required()->searchable()
                ->options(['NE' => 'NE — Not Evaluated', 'DD' => 'DD — Data Deficient', 'LC' => 'LC — Least Concern', 'NT' => 'NT — Near Threatened', 'VU' => 'VU — Vulnerable', 'EN' => 'EN — Endangered', 'CR' => 'CR — Critically Endangered', 'EW' => 'EW — Extinct in the Wild', 'EX' => 'EX — Extinct']),
            TextInput::make('assessed_year')->numeric()->minValue(1950)->maxValue(2100),
            TextInput::make('source_url')->url()->required()->helperText('Every status needs a citable source.'),
            Toggle::make('is_current')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('authority')->badge(),
                TextColumn::make('status_code')->badge()->color(fn (string $state) => in_array($state, ['VU', 'EN', 'CR', 'EW', 'EX']) ? 'danger' : 'gray'),
                TextColumn::make('assessed_year'),
                IconColumn::make('is_current')->boolean(),
                TextColumn::make('source_url')->limit(40)->url(fn ($record) => $record->source_url, true),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
