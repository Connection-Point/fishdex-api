<?php

namespace App\Http\Api\V1\Controllers;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Collection\Services\CollectionAggregator;
use App\Domain\Encounter\Actions\DecideEncounter;
use App\Domain\Encounter\Events\EncounterChanged;
use App\Domain\Encounter\Models\FishEncounter;
use App\Domain\Media\Services\MediaStore;
use App\Domain\Review\Models\ReviewItem;
use App\Domain\Shared\Enums\Decision;
use App\Domain\Shared\Enums\EncounterStatus;
use App\Domain\Shared\Enums\IdentificationSource;
use App\Domain\Shared\Support\Geohash;
use App\Domain\Shared\Support\Problem;
use App\Http\Api\V1\Presenters\Presenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EncounterController extends Controller
{
    public function __construct(private readonly Presenter $present, private readonly CollectionAggregator $collections) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'species' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'in:confirmed,unidentified,rejected'],
            'decision' => ['nullable', 'in:undecided,collect,release'],
            'trip' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $page = $request->user()->encounters()
            ->when($f['species'] ?? null, fn ($q, $id) => $q->whereHas('species', fn ($s) => $s->where('public_id', $id)))
            ->when($f['trip'] ?? null, fn ($q, $id) => $q->whereHas('trip', fn ($t) => $t->where('public_id', $id)))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['decision'] ?? null, fn ($q, $v) => $q->where('decision', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('caught_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('caught_at', '<=', $v))
            ->with(['species.names', 'species.images', 'primaryMedia', 'trip', 'region'])
            ->orderByDesc('caught_at')->orderByDesc('id')
            ->cursorPaginate($f['limit'] ?? 20);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($e) => $this->present->encounter($e)),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->present->encounter($this->find($request, $id), detail: true)]);
    }

    /** Manual journal entry without AI: never collect-eligible (anti-cheat, PRD §7.2). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->editRules() + [
            'client_uuid' => ['required', 'uuid'],
            'species_id' => ['nullable', 'string', 'size:26'],
            'caught_at' => ['required', 'date'],
            'encounter_type' => ['nullable', 'in:catch,observation'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        $user = $request->user();

        if ($existing = $user->encounters()->where('client_uuid', $data['client_uuid'])->first()) {
            return response()->json(['data' => $this->present->encounter($existing, true)]);
        }

        $species = isset($data['species_id'])
            ? FishSpecies::query()->published()->where('public_id', $data['species_id'])->firstOrFail()
            : null;

        $encounter = $user->encounters()->create($this->editable($request, $data) + [
            'species_id' => $species?->id,
            'status' => $species ? EncounterStatus::Confirmed : EncounterStatus::Unidentified,
            'identification_source' => IdentificationSource::Manual,
            'is_verified' => false,
            'collect_eligible' => false,
            'decision' => $species ? Decision::Release : Decision::Undecided,
            'encounter_type' => $data['encounter_type'] ?? 'catch',
            'caught_at' => $data['caught_at'],
            'country_id' => $user->country_id,
            'client_uuid' => $data['client_uuid'],
            'location_precise' => isset($data['lat']) ? ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']] : null,
            'geohash5' => isset($data['lat']) ? Geohash::encode((float) $data['lat'], (float) $data['lng']) : null,
            'location_visibility' => $data['location_visibility'] ?? $user->default_location_visibility,
        ]);

        if ($species) {
            $this->collections->rebuild($user->id, $species->id);
        }
        event(new EncounterChanged($user->id));

        return response()->json(['data' => $this->present->encounter($encounter, true)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $encounter = $this->find($request, $id);
        $data = $request->validate($this->editRules());

        $encounter->fill($this->editable($request, $data))->save();

        if ($encounter->species_id) {
            $this->collections->rebuild($encounter->user_id, $encounter->species_id);
        }
        event(new EncounterChanged($encounter->user_id));

        return response()->json(['data' => $this->present->encounter($encounter->fresh(), true)]);
    }

    public function decide(Request $request, string $id, DecideEncounter $decide): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:collect,release'],
            'physical_outcome' => ['nullable', 'in:released_alive,kept,unknown'],
        ]);

        $encounter = $this->find($request, $id);

        if (isset($data['physical_outcome'])) {
            $encounter->forceFill(['physical_outcome' => $data['physical_outcome']])->save();
        }

        $result = $decide->handle($encounter, Decision::from($data['decision']));
        $entry = $result['entry']->load(['card', 'raritySnapshot', 'coverMedia']);

        return response()->json(['data' => [
            'encounter' => $this->present->encounter($result['encounter']->fresh(), true),
            'collection_entry' => ['species_id' => $encounter->species->public_id] + $this->present->collectionSummary($entry),
            'is_new_species' => $result['is_new_species'],
            'is_personal_best' => $result['is_personal_best'],
            'reveal' => $result['is_new_species'] ? [
                'rarity' => $this->present->rarity($entry->raritySnapshot),
                'card' => $entry->card ? $this->present->card($entry->card) : null,
            ] : null,
            'legal_notice' => $this->present->legalNotice($encounter),
        ]]);
    }

    public function addMedia(Request $request, string $id, MediaStore $media): JsonResponse
    {
        $encounter = $this->find($request, $id);
        $request->validate(['image' => ['required', 'file', 'max:8192']]);

        if ($encounter->media()->count() >= 5) {
            throw Problem::make(422, 'too_many_photos', 'An encounter can have up to 5 photos.');
        }

        $stored = $media->storeUpload($request->user(), $request->file('image'), 'encounter');
        $encounter->media()->attach($stored->id, ['sort_order' => $encounter->media()->count()]);
        if (! $encounter->primary_media_id) {
            $encounter->forceFill(['primary_media_id' => $stored->id])->save();
        }

        return response()->json(['data' => $this->present->encounter($encounter->fresh(), true)], 201);
    }

    public function dispute(Request $request, string $id): JsonResponse
    {
        $encounter = $this->find($request, $id);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);

        $item = ReviewItem::query()->firstOrCreate(
            ['encounter_id' => $encounter->id, 'reason' => 'user_dispute', 'status' => 'open'],
            ['identification_request_id' => $encounter->identification_request_id, 'user_comment' => $data['comment'] ?? null],
        );

        return response()->json(['data' => ['review_id' => $item->public_id, 'status' => $item->status]], 202);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $encounter = $this->find($request, $id);

        DB::transaction(function () use ($encounter) {
            $encounter->delete();
            if ($encounter->species_id) {
                $this->collections->rebuild($encounter->user_id, $encounter->species_id);
            }
        });
        event(new EncounterChanged($encounter->user_id));

        return response()->json(null, 204);
    }

    private function editRules(): array
    {
        return [
            'length_cm' => ['nullable', 'numeric', 'min:0.5', 'max:5000'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.001', 'max:2000'],
            'method' => ['nullable', 'in:casting_lure,bait,fly,jigging,trolling,net,handline,observation,other'],
            'tackle_notes' => ['nullable', 'string', 'max:255'],
            'physical_outcome' => ['nullable', 'in:released_alive,kept,unknown'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'trip_id' => ['nullable', 'string', 'size:26'],
            'location_visibility' => ['nullable', 'in:private,region'],
        ];
    }

    private function editable(Request $request, array $data): array
    {
        $out = array_intersect_key($data, array_flip(['length_cm', 'weight_kg', 'method', 'tackle_notes', 'physical_outcome', 'notes', 'location_visibility']));

        if (array_key_exists('trip_id', $data)) {
            $out['trip_id'] = $data['trip_id']
                ? $request->user()->trips()->where('public_id', $data['trip_id'])->firstOrFail()->id
                : null;
        }

        return $out;
    }

    private function find(Request $request, string $id): FishEncounter
    {
        return $request->user()->encounters()->where('public_id', $id)->firstOrFail();
    }
}
