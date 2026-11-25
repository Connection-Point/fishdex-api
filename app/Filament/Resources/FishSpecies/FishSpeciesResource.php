<?php

namespace App\Filament\Resources\FishSpecies;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Filament\Concerns\AuthorizesByAdminRole;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class FishSpeciesResource extends Resource
{
    use AuthorizesByAdminRole;

    protected static ?string $model = FishSpecies::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Species';

    protected static ?string $recordTitleAttribute = 'scientific_name';

    protected static array $viewRoles = ['catalogue_editor', 'reviewer', 'support'];

    protected static array $editRoles = ['catalogue_editor'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->description('The accepted scientific name is the canonical identifier. Renames: change it here and add the old name as a synonym.')
                ->columns(2)
                ->schema([
                    TextInput::make('scientific_name')->required()->maxLength(160)->unique(ignoreRecord: true)
                        ->helperText('Genus + species, no author (e.g. Channa micropeltes)'),
                    TextInput::make('authority')->maxLength(120)->placeholder('(Cuvier, 1831)'),
                    Select::make('family_id')->relationship('family', 'scientific_name')->searchable()->preload()->required()
                        ->createOptionForm([TextInput::make('scientific_name')->required(), TextInput::make('common_name_en'), TextInput::make('common_name_ms')]),
                    Select::make('taxonomy_status')->options(['accepted' => 'Accepted', 'provisional' => 'Provisional (needs verification)', 'under_review' => 'Under review'])->required()->default('provisional'),
                    CheckboxList::make('water_types')->options(['freshwater' => 'Freshwater', 'brackish' => 'Brackish', 'marine' => 'Marine'])->required()->columns(3),
                    Toggle::make('is_published')->helperText('Published species are visible in the app and used by AI matching.')
                        ->afterStateUpdated(fn ($state, $set, $get) => $state && ! $get('published_at') ? $set('published_at', now()) : null),
                ]),
            Section::make('Field guide')
                ->columns(2)
                ->schema([
                    TextInput::make('max_length_cm')->numeric()->suffix('cm'),
                    TextInput::make('max_weight_kg')->numeric()->suffix('kg'),
                    Textarea::make('description')->rows(4)->columnSpanFull(),
                    Textarea::make('identification_notes')->rows(4)->columnSpanFull()
                        ->helperText('Key features vs look-alikes. Also sent to the AI during constrained re-ranking.'),
                    CheckboxList::make('habitats')->relationship('habitats', 'name_en')->columns(4)->columnSpanFull(),
                    Select::make('regions')->label('Recorded in states')->relationship('regions', 'name')->multiple()->preload()->columnSpanFull()
                        ->helperText('Leave empty if distribution is unknown — unknown never lowers AI confidence.'),
                    Select::make('lookalike_species_ids')->label('Often confused with')->multiple()
                        ->options(fn (?FishSpecies $record) => FishSpecies::query()->when($record, fn ($q) => $q->whereKeyNot($record->id))->orderBy('scientific_name')->pluck('scientific_name', 'id'))
                        ->searchable()->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scientific_name')->searchable()->sortable()->fontFamily(FontFamily::Serif),
                TextColumn::make('names.name')->label('Common names')->limitList(3)->listWithLineBreaks()->searchable(),
                TextColumn::make('family.scientific_name')->label('Family')->sortable()->toggleable(),
                TextColumn::make('water_types')->badge(),
                TextColumn::make('taxonomy_status')->badge()->color(fn (string $state) => match ($state) {
                    'accepted' => 'success', 'provisional' => 'warning', default => 'gray',
                }),
                IconColumn::make('is_published')->boolean()->label('Live'),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('scientific_name')
            ->filters([
                SelectFilter::make('family')->relationship('family', 'scientific_name'),
                SelectFilter::make('taxonomy_status')->options(['accepted' => 'Accepted', 'provisional' => 'Provisional', 'under_review' => 'Under review']),
                TernaryFilter::make('is_published')->label('Published'),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\NamesRelationManager::class,
            RelationManagers\SynonymsRelationManager::class,
            RelationManagers\RaritiesRelationManager::class,
            RelationManagers\CountryStatusesRelationManager::class,
            RelationManagers\ConservationAssessmentsRelationManager::class,
            RelationManagers\ImagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFishSpecies::route('/'),
            'create' => Pages\CreateFishSpecies::route('/create'),
            'edit' => Pages\EditFishSpecies::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
