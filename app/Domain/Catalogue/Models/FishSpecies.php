<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Cards\Models\FishCard;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\Support\NameNormalizer;
use Database\Factories\FishSpeciesFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A biological species. Canonical business identifier = accepted scientific name (D-02).
 */
class FishSpecies extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected $table = 'fish_species';

    protected $fillable = [
        'scientific_name', 'authority', 'genus', 'family_id', 'slug', 'water_types', 'max_length_cm',
        'max_weight_kg', 'description', 'identification_notes', 'lookalike_species_ids',
        'taxonomy_status', 'external_refs', 'is_published', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'water_types' => 'array',
            'lookalike_species_ids' => 'array',
            'external_refs' => 'array',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'max_length_cm' => 'float',
            'max_weight_kg' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $species) {
            $species->scientific_name = trim(preg_replace('/\s+/', ' ', $species->scientific_name));
            $species->genus = ucfirst(NameNormalizer::genus($species->scientific_name));
            $species->slug ??= Str::slug($species->scientific_name);
        });
    }

    protected static function newFactory(): FishSpeciesFactory
    {
        return FishSpeciesFactory::new();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(TaxonFamily::class, 'family_id');
    }

    public function names(): HasMany
    {
        return $this->hasMany(SpeciesName::class, 'species_id');
    }

    public function synonyms(): HasMany
    {
        return $this->hasMany(SpeciesSynonym::class, 'species_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SpeciesTranslation::class, 'species_id');
    }

    public function habitats(): BelongsToMany
    {
        return $this->belongsToMany(Habitat::class, 'species_habitats', 'species_id', 'habitat_id');
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'species_region_presence', 'species_id', 'region_id')
            ->withPivot(['confidence', 'source'])->withTimestamps();
    }

    public function countryStatuses(): HasMany
    {
        return $this->hasMany(SpeciesCountryStatus::class, 'species_id');
    }

    public function conservationAssessments(): HasMany
    {
        return $this->hasMany(ConservationAssessment::class, 'species_id');
    }

    public function rarities(): HasMany
    {
        return $this->hasMany(SpeciesRarity::class, 'species_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ReferenceImage::class, 'species_id')->orderBy('sort_order');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(FishCard::class, 'species_id');
    }

    /** Primary common name for a locale, optionally preferring a country-specific one. */
    public function primaryName(string $locale, ?int $countryId = null): ?string
    {
        $names = $this->relationLoaded('names') ? $this->names : $this->names()->get();

        return $names->where('locale', $locale)->where('is_primary', true)
            ->sortByDesc(fn (SpeciesName $n) => $n->country_id === $countryId ? 1 : 0)
            ->first()?->name
            ?? $names->where('locale', $locale)->first()?->name;
    }

    public function translation(string $locale): ?SpeciesTranslation
    {
        $translations = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();

        return $translations->firstWhere('locale', $locale);
    }
}
