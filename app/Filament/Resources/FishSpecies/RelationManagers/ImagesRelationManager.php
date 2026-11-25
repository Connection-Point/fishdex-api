<?php

namespace App\Filament\Resources\FishSpecies\RelationManagers;

use App\Domain\Catalogue\Models\ReferenceImage;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Verified reference photos and card art (public bucket). Licence is mandatory (doc 09 §6). */
class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Reference images & card art';

    public function form(Schema $schema): Schema
    {
        $licences = array_combine(ReferenceImage::ALLOWED_LICENSES, ReferenceImage::ALLOWED_LICENSES);

        return $schema->components([
            FileUpload::make('storage_key')->label('Image')->image()->required()
                ->disk(config('fishdex.media.assets_disk'))
                ->directory(fn (RelationManager $livewire) => 'species/'.$livewire->getOwnerRecord()->public_id)
                ->maxSize(8192)->imageEditor()->columnSpanFull(),
            Select::make('kind')->options(['reference_photo' => 'Reference photo', 'card_art' => 'Card artwork', 'silhouette' => 'Silhouette', 'diagram' => 'Diagram'])->required(),
            Select::make('license')->options($licences)->required()->helperText('Non-commercial (NC) and no-derivatives (ND) licences cannot be used.'),
            TextInput::make('attribution')->maxLength(255)->required(fn ($get) => str_starts_with((string) $get('license'), 'cc-by')),
            TextInput::make('source_url')->url()->maxLength(500),
            Toggle::make('is_primary'),
            Toggle::make('is_verified')->helperText('Only verified images are shown in the app.'),
            TextInput::make('sort_order')->numeric()->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('storage_key')->label('')->disk(config('fishdex.media.assets_disk'))->height(56),
                TextColumn::make('kind')->badge(),
                TextColumn::make('license')->badge()->color('gray'),
                TextColumn::make('attribution')->limit(30),
                IconColumn::make('is_primary')->boolean(),
                IconColumn::make('is_verified')->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data) => $this->withVerification($data)),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => $this->withVerification($data)),
                DeleteAction::make(),
            ]);
    }

    private function withVerification(array $data): array
    {
        if (! empty($data['is_verified'])) {
            $data['verified_by'] = auth('admin')->id();
            $data['verified_at'] = now();
        }
        $data['disk'] = config('fishdex.media.assets_disk');

        return $data;
    }
}
