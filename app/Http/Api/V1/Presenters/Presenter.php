<?php

namespace App\Http\Api\V1\Presenters;

use App\Domain\Cards\Models\FishCard;
use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\RarityTier;
use App\Domain\Catalogue\Services\RarityResolver;
use App\Domain\Collection\Models\UserSpeciesCollection;
use App\Domain\Encounter\Models\FishEncounter;
use App\Domain\Encounter\Models\FishingTrip;
use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Services\SignedUrlFactory;
use App\Domain\Shared\Enums\ConfidenceBand;
use App\Domain\Shared\Enums\ScanStatus;

/**
 * Single place that shapes API JSON. Only public_ids leave the API — never internal ids.
 */
class Presenter
{
    public function __construct(
        private readonly RarityResolver $rarity,
        private readonly SignedUrlFactory $urls,
    ) {}

    public function user(User $user, bool $self = true): array
    {
        $user->loadMissing(['homeRegion', 'avatar', 'country']);

        $data = [
            'id' => $user->public_id,
            'handle' => $user->handle,
            'display_name' => $user->display_name,
            'avatar' => $this->urls->for($user->avatar, 'thumb'),
        ];

        if ($self) {
            $data += [
                'email' => $user->email,
                'email_verified' => $user->email_verified_at !== null,
                'country' => $user->country->iso2,
                'home_region' => $user->homeRegion?->code,
                'locale' => $user->locale,
                'timezone' => $user->timezone,
                'units' => $user->units,
                'default_location_visibility' => $user->default_location_visibility,
                'plan' => $user->plan,
                'beta_ai_consent' => $user->beta_ai_consent,
                'created_at' => $user->created_at?->toIso8601String(),
            ];
        }

        return $data;
    }

    public function rarity(?RarityTier $tier): ?array
    {
        if (! $tier) {
            return null;
        }

        return [
            'code' => $tier->code,
            'name' => $tier->localizedName(app()->getLocale()),
            'sort_order' => $tier->sort_order,
            'color' => $tier->color_hex,
            'foil' => $tier->foil_effect,
            'reveal_animation' => $tier->reveal_animation,
        ];
    }

    public function names(FishSpecies $s, int $countryId): array
    {
        return [
            'en' => $s->primaryName('en', $countryId),
            'ms' => $s->primaryName('ms', $countryId),
        ];
    }

    public function thumbnail(FishSpecies $s): ?string
    {
        // Only curator-verified images are ever shown to anglers. Card art wins over reference photos.
        $verified = $s->relationLoaded('images') ? $s->images->where('is_verified', true) : collect();
        $art = $verified->where('kind', 'card_art');
        $img = $art->firstWhere('is_primary', true) ?? $art->first()
            ?? $verified->firstWhere('is_primary', true) ?? $verified->first();

        return $img?->url();
    }

    /** Compact list item. $userState: unseen|seen|collected */
    public function speciesItem(FishSpecies $s, int $countryId, ?string $userState = null, ?FishCard $card = null): array
    {
        return [
            'id' => $s->public_id,
            'scientific_name' => $s->scientific_name,
            'names' => $this->names($s, $countryId),
            'family' => $s->relationLoaded('family') ? $s->family->scientific_name : null,
            'water_types' => $s->water_types,
            'rarity' => $this->rarity($this->rarity->tierFor($s->id, $countryId)),
            'thumbnail_url' => $this->thumbnail($s),
            'card' => $card ? $this->card($card) : null,
            'user_state' => $userState,
        ];
    }

    public function speciesDetail(FishSpecies $s, int $countryId, ?UserSpeciesCollection $entry, ?FishCard $card): array
    {
        $locale = app()->getLocale();
        $t = $s->translation($locale) ?? $s->translation('en');
        $status = $s->countryStatuses->firstWhere('country_id', $countryId);

        return $this->speciesItem($s, $countryId, $entry?->state->value ?? 'unseen', $card) + [
            'authority' => $s->authority,
            'aliases' => $s->names
                ->filter(fn ($n) => $n->country_id === null || $n->country_id === $countryId)
                ->map(fn ($n) => ['name' => $n->name, 'locale' => $n->locale, 'type' => $n->name_type])
                ->values(),
            'taxonomy' => [
                'family' => $s->family->scientific_name,
                'family_common_name' => $locale === 'ms' ? ($s->family->common_name_ms ?? $s->family->common_name_en) : $s->family->common_name_en,
                'order' => $s->family->order_name,
                'genus' => $s->genus,
            ],
            'description' => $t?->description ?? $s->description,
            'identification_notes' => $t?->identification_notes ?? $s->identification_notes,
            'fun_fact' => $t?->fun_fact,
            'max_length_cm' => $s->max_length_cm,
            'max_weight_kg' => $s->max_weight_kg,
            'habitats' => $s->habitats->map(fn ($h) => ['code' => $h->code, 'name' => $h->localizedName($locale)])->values(),
            'distribution' => [
                'origin' => $status?->origin,
                'notes' => $status?->distribution_notes,
                'regions' => $s->regions->where('country_id', $countryId)->map(fn ($r) => ['code' => $r->code, 'name' => $r->name])->values(),
            ],
            'conservation' => $s->conservationAssessments->where('is_current', true)
                ->filter(fn ($a) => $a->country_id === null || $a->country_id === $countryId)
                ->map(fn ($a) => ['authority' => $a->authority, 'status' => $a->status_code, 'year' => $a->assessed_year, 'source_url' => $a->source_url])
                ->values(),
            'legal_notice' => $status && $status->legal_protection !== 'none'
                ? ['protection' => $status->legal_protection, 'notes' => $status->legal_notes]
                : null,
            'images' => $s->images->where('is_verified', true)->sortBy(fn ($i) => $i->kind === 'card_art' ? 0 : 1)->map(fn ($i) => [
                'id' => $i->public_id,
                'kind' => $i->kind,
                'url' => $i->url(),
                'width' => $i->width,
                'height' => $i->height,
                'blurhash' => $i->blurhash,
                'license' => $i->license,
                'attribution' => $i->attribution,
            ])->values(),
            'lookalikes' => FishSpecies::query()->published()->whereIn('id', $s->lookalike_species_ids ?? [])
                ->with('names')->get()
                ->map(fn ($l) => ['id' => $l->public_id, 'scientific_name' => $l->scientific_name, 'names' => $this->names($l, $countryId)]),
            'collection' => $entry ? $this->collectionSummary($entry) : null,
        ];
    }

    public function card(FishCard $card): array
    {
        $card->loadMissing(['set', 'artwork']);

        return [
            'id' => $card->public_id,
            'set' => $card->set->code,
            'number' => $card->card_number,
            'variant' => $card->variant,
            'art_url' => $card->artwork?->url(),
            'attributes' => $card->display_attributes,
        ];
    }

    public function collectionSummary(UserSpeciesCollection $e): array
    {
        return [
            'state' => $e->state->value,
            'encounter_count' => $e->encounter_count,
            'collected_count' => $e->collected_count,
            'released_count' => $e->released_count,
            'first_seen_at' => $e->first_seen_at?->toIso8601String(),
            'first_collected_at' => $e->first_collected_at?->toIso8601String(),
            'best_length_cm' => $e->best_length_cm,
            'best_weight_kg' => $e->best_weight_kg,
            'is_favorite' => $e->is_favorite,
            'rarity_at_collection' => $this->rarity($e->raritySnapshot),
            'cover' => $this->urls->set($e->coverMedia),
        ];
    }

    public function scan(IdentificationRequest $scan): array
    {
        $scan->loadMissing(['candidates.species.names', 'candidates.species.images', 'media', 'encounter']);

        $completed = $scan->status === ScanStatus::Completed;
        $candidates = $completed ? $scan->candidates->filter(fn ($c) => $c->matched_species_id !== null)->take(3)->values() : collect();

        return [
            'scan_id' => $scan->public_id,
            'status' => $scan->status->value,
            'outcome' => $scan->outcome?->value,
            'confidence_band' => $scan->confidence_band?->value,
            'candidates' => $candidates->map(fn ($c) => [
                'rank' => $c->rank,
                'species' => $this->speciesItem($c->species, $scan->country_id),
                'confidence' => round($c->final_confidence, 2),
                'reasons' => $c->visible_features ?? [],
            ]),
            'preselected_species_id' => $completed && $scan->confidence_band === ConfidenceBand::High
                ? $scan->candidates->firstWhere('matched_species_id', '!=', null)?->species?->public_id
                : null,
            'flags' => $scan->flags ?? [],
            'expected_after' => $scan->status === ScanStatus::WaitingCapacity ? $scan->expected_after?->toIso8601String() : null,
            'poll_after_ms' => $scan->status->isTerminal() ? null : ($scan->status === ScanStatus::WaitingCapacity ? 60000 : 1500),
            'error_code' => in_array($scan->status, [ScanStatus::Failed, ScanStatus::TimedOut, ScanStatus::Rejected], true) ? $scan->error_code : null,
            'encounter_id' => $scan->encounter?->public_id,
            'captured_at' => $scan->captured_at?->toIso8601String(),
            'image' => $this->urls->set($scan->media),
            'created_at' => $scan->created_at?->toIso8601String(),
        ];
    }

    public function encounter(FishEncounter $e, bool $detail = false): array
    {
        $e->loadMissing(['species.names', 'species.images', 'primaryMedia', 'trip', 'region']);

        $data = [
            'id' => $e->public_id,
            'status' => $e->status->value,
            'species' => $e->species ? $this->speciesItem($e->species, $e->country_id) : null,
            'identification_source' => $e->identification_source->value,
            'is_verified' => $e->is_verified,
            'confidence_band' => $e->confidence_band?->value,
            'decision' => $e->decision->value,
            'collect_eligible' => $e->collect_eligible,
            'encounter_type' => $e->encounter_type,
            'physical_outcome' => $e->physical_outcome,
            'caught_at' => $e->caught_at?->toIso8601String(),
            'length_cm' => $e->length_cm,
            'weight_kg' => $e->weight_kg,
            'method' => $e->method,
            'region' => $e->region?->code,
            'trip_id' => $e->trip?->public_id,
            'photo' => $this->urls->set($e->primaryMedia),
        ];

        if ($detail) {
            $e->loadMissing('media');
            $data += [
                'tackle_notes' => $e->tackle_notes,
                'notes' => $e->notes,
                'location' => $e->location_precise, // owner only — endpoint is owner-scoped
                'location_visibility' => $e->location_visibility,
                'photos' => $e->media->map(fn ($m) => ['id' => $m->public_id] + $this->urls->set($m))->values(),
                'legal_notice' => $this->legalNotice($e),
            ];
        }

        return $data;
    }

    public function legalNotice(FishEncounter $e): ?array
    {
        if (! $e->species_id) {
            return null;
        }

        $status = $e->species->countryStatuses()->where('country_id', $e->country_id)->first();

        return $status && $status->isProtected()
            ? ['protection' => $status->legal_protection, 'message' => $status->legal_notes ?: __('This species is protected. Please release it carefully.')]
            : null;
    }

    public function trip(FishingTrip $t): array
    {
        return [
            'id' => $t->public_id,
            'title' => $t->title,
            'started_at' => $t->started_at?->toIso8601String(),
            'ended_at' => $t->ended_at?->toIso8601String(),
            'water_body_type' => $t->water_body_type,
            'region' => $t->region?->code,
            'notes' => $t->notes,
            'encounter_count' => $t->encounters_count ?? $t->encounters()->count(),
        ];
    }
}
