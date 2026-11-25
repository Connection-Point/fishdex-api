<?php

namespace App\Domain\Identification\Models;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\Region;
use App\Domain\Encounter\Models\FishEncounter;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Shared\Concerns\HasPublicId;
use App\Domain\Shared\Enums\ConfidenceBand;
use App\Domain\Shared\Enums\ScanOutcome;
use App\Domain\Shared\Enums\ScanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One scan. Exposed to the API as `scan_id` (= public_id). */
class IdentificationRequest extends Model
{
    use HasPublicId;

    protected $fillable = [
        'user_id', 'media_id', 'client_uuid', 'country_id', 'region_id', 'encounter_type', 'trip_id', 'captured_at',
        'location_precise', 'geohash5', 'status', 'outcome', 'confidence_band', 'top_species_id', 'top_confidence',
        'flags', 'total_cost_usd_micros', 'attempt_count', 'ai_tier', 'queued_at', 'expected_after', 'started_at',
        'completed_at', 'error_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScanStatus::class,
            'outcome' => ScanOutcome::class,
            'confidence_band' => ConfidenceBand::class,
            'flags' => 'array',
            'location_precise' => 'encrypted:array',
            'top_confidence' => 'float',
            'captured_at' => 'datetime',
            'queued_at' => 'datetime',
            'expected_after' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function topSpecies(): BelongsTo
    {
        return $this->belongsTo(FishSpecies::class, 'top_species_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(IdentificationAttempt::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(IdentificationCandidate::class)->orderBy('rank');
    }

    public function encounter(): HasOne
    {
        return $this->hasOne(FishEncounter::class);
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags ?? [], true);
    }
}
