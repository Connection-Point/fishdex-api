<?php

namespace App\Domain\Encounter\Actions;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Collection\Services\CollectionAggregator;
use App\Domain\Encounter\Models\FishEncounter;
use App\Domain\Encounter\Services\EligibilityPolicy;
use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Media\Services\MediaStore;
use App\Domain\Review\Models\ReviewItem;
use App\Domain\Shared\Enums\ConfidenceBand;
use App\Domain\Shared\Enums\EncounterStatus;
use App\Domain\Shared\Enums\IdentificationSource;
use App\Domain\Shared\Enums\ScanStatus;
use App\Domain\Shared\Support\Problem;
use Illuminate\Support\Facades\DB;

/**
 * Step 8: the user confirms what the fish is. Creates exactly one encounter per scan.
 *
 * choice = species    → ai_confirmed (pick from high/medium top-3) or user_selected (anything else)
 * choice = not_listed → unidentified encounter (journal only)
 * choice = unsure     → unidentified encounter (journal only)
 */
class ConfirmIdentification
{
    public function __construct(
        private readonly EligibilityPolicy $eligibility,
        private readonly CollectionAggregator $collections,
        private readonly MediaStore $media,
    ) {}

    public function handle(IdentificationRequest $scan, string $choice, ?FishSpecies $species = null): FishEncounter
    {
        if ($scan->status !== ScanStatus::Completed) {
            throw Problem::make(409, 'scan_not_ready', 'This scan has not finished identifying yet.');
        }
        if ($scan->encounter()->withTrashed()->exists()) {
            throw Problem::make(409, 'already_confirmed', 'This scan was already confirmed.');
        }
        if ($choice === 'species' && ($species === null || ! $species->is_published)) {
            throw Problem::make(422, 'validation_failed', 'Choose a published species.', ['errors' => ['species_id' => ['Unknown species.']]]);
        }

        $source = $choice === 'species' ? $this->source($scan, $species) : IdentificationSource::UserSelected;
        $hasSpecies = $choice === 'species';
        $verdict = $this->eligibility->evaluate($scan, $source, $hasSpecies);

        return DB::transaction(function () use ($scan, $species, $source, $hasSpecies, $verdict) {
            $encounter = FishEncounter::create([
                'user_id' => $scan->user_id,
                'trip_id' => $scan->trip_id,
                'species_id' => $hasSpecies ? $species->id : null,
                'identification_request_id' => $scan->id,
                'primary_media_id' => $scan->media_id,
                'status' => $hasSpecies ? EncounterStatus::Confirmed : EncounterStatus::Unidentified,
                'identification_source' => $source,
                'confidence_band' => $scan->confidence_band,
                'is_verified' => $verdict['verified'],
                'collect_eligible' => $verdict['eligible'],
                'encounter_type' => $scan->encounter_type,
                'caught_at' => $scan->captured_at,
                'location_precise' => $scan->location_precise,
                'geohash5' => $scan->geohash5,
                'region_id' => $scan->region_id,
                'country_id' => $scan->country_id,
                'location_visibility' => $scan->user->default_location_visibility ?? 'private',
                'client_uuid' => $scan->client_uuid,
            ]);

            $encounter->media()->attach($scan->media_id, ['sort_order' => 0]);
            $this->media->retain($scan->media);

            if ($verdict['review_reason']) {
                ReviewItem::create([
                    'encounter_id' => $encounter->id,
                    'identification_request_id' => $scan->id,
                    'reason' => $verdict['review_reason'],
                ]);
            }

            if ($hasSpecies) {
                // Confirmed → the species is at least "seen" in the FishDex (D-05).
                $this->collections->rebuild($encounter->user_id, $species->id);
            }

            return $encounter;
        });
    }

    private function source(IdentificationRequest $scan, FishSpecies $species): IdentificationSource
    {
        $inTop3 = $scan->candidates()->where('rank', '<=', 3)->where('matched_species_id', $species->id)->exists();

        return $inTop3 && in_array($scan->confidence_band, [ConfidenceBand::High, ConfidenceBand::Medium], true)
            ? IdentificationSource::AiConfirmed
            : IdentificationSource::UserSelected;
    }
}
