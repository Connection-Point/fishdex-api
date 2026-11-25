<?php

namespace App\Domain\Identification\Models;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Shared\Enums\MatchMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentificationCandidate extends Model
{
    protected $fillable = [
        'identification_request_id', 'rank', 'raw_label', 'raw_scientific_name', 'model_confidence', 'matched_species_id',
        'match_method', 'match_score', 'geo_plausible', 'final_confidence', 'visible_features',
    ];

    protected function casts(): array
    {
        return [
            'match_method' => MatchMethod::class,
            'model_confidence' => 'float',
            'match_score' => 'float',
            'final_confidence' => 'float',
            'geo_plausible' => 'boolean',
            'visible_features' => 'array',
        ];
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(FishSpecies::class, 'matched_species_id');
    }
}
