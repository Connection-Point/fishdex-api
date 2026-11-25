<?php

namespace App\Filament\Resources\FishSpecies\RelationManagers;

use App\Domain\Catalogue\Models\Country;
use App\Domain\Catalogue\Models\RarityTier;
use App\Domain\Catalogue\Services\RarityResolver;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Rarity is game data set by staff, never by AI (D-06). History is append-only:
 * assigning a new tier closes the current one instead of editing it.
 */
class RaritiesRelationManager extends RelationManager
{
    protected static string $relationship = 'rarities';

    protected static ?string $title = 'Rarity (history)';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('country_id')->label('Country')->options(fn () => Country::query()->pluck('name', 'id'))->required()
                ->default(fn () => Country::byIso(config('fishdex.default_country'))->id),
            Select::make('rarity_tier_id')->label('Tier')->options(fn () => RarityTier::query()->orderBy('sort_order')->pluck('name_en', 'id'))->required(),
            Select::make('basis')->options(['editorial' => 'Editorial', 'computed' => 'Computed from data', 'event' => 'Event'])->default('editorial')->required(),
            Textarea::make('rationale')->required()->maxLength(500)
                ->helperText('Why this tier? Base it on how hard the fish is to encounter — never on how endangered it is.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('country.iso2')->label('Country'),
                TextColumn::make('tier.name_en')->label('Tier')->badge()->color(fn ($record) => Color::hex($record->tier->color_hex)),
                TextColumn::make('effective_from')->date(),
                TextColumn::make('effective_to')->date()->placeholder('current'),
                TextColumn::make('rationale')->limit(60)->wrap(),
                TextColumn::make('setBy.name')->label('Set by')->placeholder('system'),
            ])
            ->headerActions([
                CreateAction::make()->label('Assign new tier')
                    ->using(fn (array $data, RelationManager $livewire): Model => app(RarityResolver::class)->assign(
                        $livewire->getOwnerRecord()->id,
                        (int) $data['country_id'],
                        (int) $data['rarity_tier_id'],
                        $data['rationale'],
                        auth('admin')->id(),
                        $data['basis'],
                    )),
            ]);
    }
}
