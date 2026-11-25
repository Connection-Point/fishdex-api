<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per (species, country): origin, presence and legal protection. */
class SpeciesCountryStatus extends Model
{
    protected $table = 'species_country_status';

    protected $fillable = ['species_id', 'country_id', 'origin', 'is_present', 'distribution_notes', 'legal_protection', 'legal_notes', 'source'];

    protected function casts(): array
    {
        return ['is_present' => 'boolean'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function isProtected(): bool
    {
        return in_array($this->legal_protection, ['protected', 'sanctuary_only'], true);
    }
}
