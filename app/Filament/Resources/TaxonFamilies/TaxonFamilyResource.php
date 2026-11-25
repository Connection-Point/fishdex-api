<?php

namespace App\Filament\Resources\TaxonFamilies;

use App\Domain\Catalogue\Models\TaxonFamily;
use App\Filament\Concerns\AuthorizesByAdminRole;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TaxonFamilyResource extends Resource
{
    use AuthorizesByAdminRole;

    protected static ?string $model = TaxonFamily::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Families';

    protected static array $viewRoles = ['catalogue_editor', 'reviewer'];

    protected static array $editRoles = ['catalogue_editor'];

    protected static array $forbiddenActions = ['delete', 'deleteAny'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('scientific_name')->required()->maxLength(120)->unique(ignoreRecord: true),
            TextInput::make('order_name')->maxLength(120),
            TextInput::make('common_name_en')->maxLength(120),
            TextInput::make('common_name_ms')->maxLength(120),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scientific_name')->searchable()->sortable(),
                TextColumn::make('order_name')->searchable(),
                TextColumn::make('common_name_en')->searchable(),
                TextColumn::make('common_name_ms')->searchable(),
                TextColumn::make('species_count')->counts('species')->label('Species'),
            ])
            ->defaultSort('scientific_name')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTaxonFamilies::route('/')];
    }
}
