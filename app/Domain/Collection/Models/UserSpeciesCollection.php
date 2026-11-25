<?php

namespace App\Domain\Collection\Models;

use App\Domain\Cards\Models\FishCard;
use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\RarityTier;
use App\Domain\Encounter\Models\FishEncounter;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Shared\Enums\CollectionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Exactly one row per (user, species). Aggregate of that user's encounters. */
class UserSpeciesCollection extends Model
{
    protected $fillable = [
        'user_id', 'species_id', 'state', 'card_id', 'rarity_tier_id_snapshot', 'first_seen_at', 'first_collected_at',
        'first_encounter_id', 'first_collected_encounter_id', 'latest_encounter_id', 'encounter_count', 'collected_count',
        'released_count', 'best_length_cm', 'best_length_encounter_id', 'best_weight_kg', 'best_weight_encounter_id',
        'cover_media_id', 'is_favorite',
    ];

    protected function casts(): array
    {
        return [
            'state' => CollectionState::class,
            'first_seen_at' => 'datetime',
            'first_collected_at' => 'datetime',
            'best_length_cm' => 'float',
            'best_weight_kg' => 'float',
            'is_favorite' => 'boolean',
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

    public function card(): BelongsTo
    {
        return $this->belongsTo(FishCard::class, 'card_id');
    }

    public function raritySnapshot(): BelongsTo
    {
        return $this->belongsTo(RarityTier::class, 'rarity_tier_id_snapshot');
    }

    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function firstEncounter(): BelongsTo
    {
        return $this->belongsTo(FishEncounter::class, 'first_encounter_id');
    }

    public function bestLengthEncounter(): BelongsTo
    {
        return $this->belongsTo(FishEncounter::class, 'best_length_encounter_id');
    }

    public function bestWeightEncounter(): BelongsTo
    {
        return $this->belongsTo(FishEncounter::class, 'best_weight_encounter_id');
    }
}
