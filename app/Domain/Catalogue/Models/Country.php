<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Shared\Support\Problem;
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

    /**
     * Reference data must be seeded (ReferenceDataSeeder). A missing country is a server setup
     * problem, so it is reported as 503 `server_not_configured` rather than a misleading 404.
     */
    public static function byIso(string $iso2): self
    {
        return static::query()->where('iso2', strtoupper($iso2))->first()
            ?? throw Problem::make(
                503,
                'server_not_configured',
                "Reference data is missing (country {$iso2}). Run: php artisan db:seed --class=ReferenceDataSeeder",
            );
    }
}
