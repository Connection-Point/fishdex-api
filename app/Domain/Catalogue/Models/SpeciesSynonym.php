<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Shared\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeciesSynonym extends Model
{
    protected $fillable = ['species_id', 'scientific_name', 'kind', 'source'];

    protected static function booted(): void
    {
        static::saving(fn (self $s) => $s->normalized_name = NameNormalizer::scientific($s->scientific_name));
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(FishSpecies::class, 'species_id');
    }
}
