<?php

namespace App\Domain\Cards\Models;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\ReferenceImage;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The visual collectible for a species within a set. Biology lives on FishSpecies. */
class FishCard extends Model
{
    use HasPublicId;

    protected $fillable = ['card_set_id', 'species_id', 'card_number', 'variant', 'artwork_image_id', 'flavor_text_key', 'display_attributes', 'is_active'];

    protected function casts(): array
    {
        return ['display_attributes' => 'array', 'is_active' => 'boolean'];
    }

    public function set(): BelongsTo
    {
        return $this->belongsTo(CardSet::class, 'card_set_id');
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(FishSpecies::class, 'species_id');
    }

    public function artwork(): BelongsTo
    {
        return $this->belongsTo(ReferenceImage::class, 'artwork_image_id');
    }
}
