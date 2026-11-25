<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxonFamily extends Model
{
    protected $fillable = ['scientific_name', 'order_name', 'common_name_en', 'common_name_ms'];

    public function species(): HasMany
    {
        return $this->hasMany(FishSpecies::class, 'family_id');
    }
}
