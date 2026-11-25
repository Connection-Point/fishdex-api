<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeciesTranslation extends Model
{
    protected $fillable = ['species_id', 'locale', 'description', 'identification_notes', 'fun_fact'];

    public function species(): BelongsTo
    {
        return $this->belongsTo(FishSpecies::class, 'species_id');
    }
}
