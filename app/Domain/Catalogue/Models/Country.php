<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $fillable = ['iso2', 'name', 'default_locale', 'is_launched', 'sort_order'];

    protected function casts(): array
    {
        return ['is_launched' => 'boolean'];
    }

    public function regions(): HasMany
    {
        return $this->hasMany(Region::class);
    }

    public static function byIso(string $iso2): self
    {
        return static::query()->where('iso2', strtoupper($iso2))->firstOrFail();
    }
}
