<?php

namespace App\Filament\Resources\CardSets;

use App\Domain\Cards\Models\CardSet;
use App\Domain\Catalogue\Models\Country;
use App\Filament\Concerns\AuthorizesByAdminRole;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CardSetResource extends Resource
{
    use AuthorizesByAdminRole;

    protected static ?string $model = CardSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Cards';

    protected static ?string $recordTitleAttribute = 'name';

    protected static array $viewRoles = ['catalogue_editor', 'reviewer', 'support'];

    protected static array $editRoles = ['catalogue_editor'];

    protected static array $forbiddenActions = ['delete', 'deleteAny'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(30)->unique(ignoreRecord: true)->helperText('Shown on cards, e.g. MY-S1'),
            TextInput::make('name')->required()->maxLength(120),
            Select::make('country_id')->label('Country')->options(fn () => Country::query()->pluck('name', 'id')),
            TextInput::make('theme')->maxLength(60),
            DatePicker::make('released_at'),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->badge(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('country.iso2')->label('Country'),
                TextColumn::make('cards_count')->counts('cards')->label('Cards'),
                TextColumn::make('released_at')->date(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\CardsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCardSets::route('/'),
            'create' => Pages\CreateCardSet::route('/create'),
            'edit' => Pages\EditCardSet::route('/{record}/edit'),
        ];
    }
}
