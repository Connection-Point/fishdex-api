<?php

namespace App\Filament\Resources\ReviewItems;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Media\Services\SignedUrlFactory;
use App\Domain\Review\Actions\ResolveReview;
use App\Domain\Review\Models\ReviewItem;
use App\Filament\Concerns\AuthorizesByAdminRole;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Expert review queue (doc 08 §12): disputes, low-confidence user picks, duplicates,
 * suspected screen photos and random samples. Reviewers only see the photo, the AI
 * candidates and the chosen species — never the angler's precise location.
 */
class ReviewItemResource extends Resource
{
    use AuthorizesByAdminRole;

    protected static ?string $model = ReviewItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?string $navigationLabel = 'Review queue';

    protected static array $viewRoles = ['reviewer', 'catalogue_editor'];

    protected static array $editRoles = ['reviewer'];

    protected static array $forbiddenActions = ['create', 'delete', 'deleteAny'];

    public static function getNavigationBadge(): ?string
    {
        $open = ReviewItem::query()->where('status', 'open')->count();

        return $open ? (string) $open : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'encounter.species', 'encounter.primaryMedia', 'identificationRequest.candidates.species',
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->columns([
                ImageColumn::make('photo')->label('')->height(72)
                    ->state(fn (ReviewItem $r) => app(SignedUrlFactory::class)->for($r->encounter?->primaryMedia, 'thumb')['url'] ?? null),
                TextColumn::make('reason')->badge()->color(fn (string $state) => match ($state) {
                    'user_dispute' => 'warning', 'duplicate_image', 'possible_screen_photo' => 'danger', default => 'gray',
                }),
                TextColumn::make('encounter.species.scientific_name')->label('Chosen species')->placeholder('—'),
                TextColumn::make('ai')->label('AI top 3')->listWithLineBreaks()
                    ->state(fn (ReviewItem $r) => $r->identificationRequest?->candidates->take(3)
                        ->map(fn ($c) => ($c->species?->scientific_name ?? $c->raw_scientific_name ?? '?').' · '.round($c->final_confidence * 100).'%')->all() ?? []),
                TextColumn::make('identificationRequest.confidence_band')->label('Band')->badge(),
                TextColumn::make('user_comment')->limit(40)->wrap()->placeholder('—'),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['open' => 'Open', 'confirmed' => 'Confirmed', 'corrected' => 'Corrected', 'rejected' => 'Rejected'])->default('open'),
                SelectFilter::make('reason')->options([
                    'user_dispute' => 'User dispute', 'low_confidence_user_pick' => 'Low-confidence pick', 'duplicate_image' => 'Duplicate image',
                    'possible_screen_photo' => 'Possible screen photo', 'random_sample' => 'Random sample', 'report' => 'Report',
                ]),
            ])
            ->recordActions([
                Action::make('confirm')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (ReviewItem $r) => $r->status === 'open' && $r->encounter?->species_id)
                    ->requiresConfirmation()
                    ->action(fn (ReviewItem $r) => static::resolve($r, 'confirmed')),
                Action::make('correct')->icon(Heroicon::OutlinedPencilSquare)->color('warning')
                    ->visible(fn (ReviewItem $r) => $r->status === 'open')
                    ->schema([
                        Select::make('species_id')->label('Correct species')->required()->searchable()
                            ->options(fn () => FishSpecies::query()->published()->orderBy('scientific_name')->pluck('scientific_name', 'id')),
                        Textarea::make('notes')->maxLength(1000),
                    ])
                    ->action(fn (ReviewItem $r, array $data) => static::resolve($r, 'corrected', FishSpecies::find($data['species_id']), $data['notes'] ?? null)),
                Action::make('reject')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (ReviewItem $r) => $r->status === 'open')
                    ->schema([Textarea::make('notes')->label('Reason (e.g. internet photo)')->required()])
                    ->action(fn (ReviewItem $r, array $data) => static::resolve($r, 'rejected', null, $data['notes'])),
            ]);
    }

    private static function resolve(ReviewItem $item, string $outcome, ?FishSpecies $species = null, ?string $notes = null): void
    {
        app(ResolveReview::class)->handle($item, $outcome, auth('admin')->user(), $species, $notes);
        Notification::make()->title('Review '.$outcome)->success()->send();
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReviewItems::route('/')];
    }
}
