<?php

namespace App\Filament\Resources\Admins;

use App\Domain\Admin\Models\Admin;
use App\Domain\Shared\Enums\AdminRole;
use App\Filament\Concerns\AuthorizesByAdminRole;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/** Staff accounts — super admins only. */
class AdminResource extends Resource
{
    use AuthorizesByAdminRole;

    protected static ?string $model = Admin::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Staff';

    protected static array $viewRoles = ['super_admin'];

    protected static array $editRoles = ['super_admin'];

    protected static array $forbiddenActions = ['delete', 'deleteAny'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            Select::make('role')->options(collect(AdminRole::cases())->mapWithKeys(fn ($r) => [$r->value => str($r->value)->headline()]))->required(),
            TextInput::make('password')->password()->revealable()
                ->rule(Password::min(12))
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state)),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('email'),
                TextColumn::make('role')->badge(),
                IconColumn::make('two_factor_confirmed_at')->label('2FA')->boolean(fn ($state) => $state !== null),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('last_login_at')->since(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAdmins::route('/')];
    }
}
