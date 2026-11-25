<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Region extends Model
{
    protected $fillable = ['country_id', 'code', 'name', 'type'];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
