<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Admin\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Versioned, country-scoped game rarity. Assigned by admins, never by AI (D-06). */
class SpeciesRarity extends Model
{
    protected $fillable = ['species_id', 'country_id', 'rarity_tier_id', 'basis', 'rationale', 'effective_from', 'effective_to', 'set_by_admin_id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(FishSpecies::class, 'species_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(RarityTier::class, 'rarity_tier_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'set_by_admin_id');
    }
}
