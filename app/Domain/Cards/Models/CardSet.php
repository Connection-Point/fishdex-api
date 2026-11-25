<?php

namespace App\Domain\Cards\Models;

use App\Domain\Catalogue\Models\Country;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardSet extends Model
{
    use HasPublicId;

    protected $fillable = ['code', 'name', 'country_id', 'theme', 'released_at', 'is_active', 'total_cards'];

    protected function casts(): array
    {
        return ['released_at' => 'date', 'is_active' => 'boolean'];
    }

    public function cards(): HasMany
    {
        return $this->hasMany(FishCard::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
