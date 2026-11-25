<?php

namespace App\Domain\Encounter\Models;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\Region;
use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\Enums\ConfidenceBand;
use App\Domain\Shared\Enums\Decision;
use App\Domain\Shared\Enums\EncounterStatus;
use App\Domain\Shared\Enums\IdentificationSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One real-world catch/observation event. Many per user per species (D-04). */
class FishEncounter extends Model
{
    use HasPublicId, SoftDeletes;

    protected $fillable = [
        'user_id', 'trip_id', 'species_id', 'identification_request_id', 'primary_media_id', 'status',
        'identification_source', 'confidence_band', 'is_verified', 'decision', 'decision_at', 'collect_eligible',
        'encounter_type', 'physical_outcome', 'caught_at', 'length_cm', 'weight_kg', 'method', 'tackle_notes',
        'location_precise', 'geohash5', 'region_id', 'country_id', 'location_visibility', 'notes', 'weather', 'client_uuid',
    ];

    /** Mirror DB defaults so freshly created models are complete. */
    protected $attributes = [
        'decision' => 'undecided',
        'is_verified' => false,
        'collect_eligible' => false,
        'encounter_type' => 'catch',
        'location_visibility' => 'private',
    ];

    protected function casts(): array
    {
        return [
            'status' => EncounterStatus::class,
            'identification_source' => IdentificationSource::class,
            'confidence_band' => ConfidenceBand::class,
            'decision' => Decision::class,
            'is_verified' => 'boolean',
            'collect_eligible' => 'boolean',
            'decision_at' => 'datetime',
            'caught_at' => 'datetime',
            'length_cm' => 'float',
            'weight_kg' => 'float',
            'location_precise' => 'encrypted:array',
            'weather' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(FishSpecies::class, 'species_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(FishingTrip::class, 'trip_id');
    }

    public function identificationRequest(): BelongsTo
    {
        return $this->belongsTo(IdentificationRequest::class);
    }

    public function primaryMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'primary_media_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'encounter_media', 'encounter_id', 'media_id')
            ->withPivot('sort_order')->orderByPivot('sort_order');
    }

    public function isConfirmed(): bool
    {
        return $this->status === EncounterStatus::Confirmed && $this->species_id !== null;
    }
}
