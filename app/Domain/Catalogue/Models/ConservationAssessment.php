<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Biological/legal status. Never used to derive game rarity. */
class ConservationAssessment extends Model
{
    protected $fillable = ['species_id', 'authority', 'country_id', 'status_code', 'assessed_year', 'source_url', 'is_current'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean'];
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(FishSpecies::class, 'species_id');
    }
}
