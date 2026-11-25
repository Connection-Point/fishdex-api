<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Shared\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeciesName extends Model
{
    protected $fillable = ['species_id', 'name', 'locale', 'country_id', 'region_id', 'name_type', 'is_primary', 'is_ambiguous'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'is_ambiguous' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $n) => $n->normalized_name = NameNormalizer::common($n->name));
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(FishSpecies::class, 'species_id');
    }
}
