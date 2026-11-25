<?php

namespace App\Filament\Resources\Users;

use App\Domain\Identity\Models\User;
use App\Filament\Concerns\AuthorizesByAdminRole;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Support view of players. Read-only except suspend/reinstate; no access to
 * passwords, tokens or precise locations.
 */
class UserResource extends Resource
{
    use AuthorizesByAdminRole;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Players';

    protected static ?string $navigationLabel = 'Anglers';

    protected static array $viewRoles = ['support'];

    protected static array $editRoles = ['support'];

    protected static array $forbiddenActions = ['create', 'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny'];

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('handle')->searchable(),
                TextColumn::make('display_name')->searchable(),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('homeRegion.name')->label('Home state')->placeholder('—'),
                TextColumn::make('plan')->badge(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'danger'),
                TextColumn::make('stats.species_collected')->label('Collected')->placeholder('0'),
                TextColumn::make('last_active_at')->since()->sortable(),
                TextColumn::make('created_at')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'suspended' => 'Suspended', 'pending_deletion' => 'Pending deletion']),
                SelectFilter::make('plan')->options(['free' => 'Free', 'premium' => 'Premium']),
            ])
            ->recordActions([
                Action::make('suspend')->color('danger')->icon(Heroicon::OutlinedLockClosed)
                    ->visible(fn (User $u) => $u->status === 'active')
                    ->requiresConfirmation()
                    ->action(function (User $u) {
                        $u->forceFill(['status' => 'suspended'])->save();
                        $u->tokens()->delete();
                    }),
                Action::make('reinstate')->color('success')->icon(Heroicon::OutlinedLockOpen)
                    ->visible(fn (User $u) => $u->status === 'suspended')
                    ->requiresConfirmation()
                    ->action(fn (User $u) => $u->forceFill(['status' => 'active'])->save()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUsers::route('/')];
    }
}
