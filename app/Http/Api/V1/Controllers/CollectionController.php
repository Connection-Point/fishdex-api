<?php

namespace App\Http\Api\V1\Controllers;

use App\Domain\Cards\Models\CardSet;
use App\Domain\Cards\Models\FishCard;
use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Services\RarityResolver;
use App\Domain\Collection\Models\UserSpeciesCollection;
use App\Domain\Encounter\Models\FishEncounter;
use App\Domain\Media\Models\Media;
use App\Domain\Shared\Support\Problem;
use App\Http\Api\V1\Presenters\Presenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The TCG FishDex: every card in a set, with the user's state
 * (unseen = silhouette, seen = released/confirmed only, collected = card owned).
 */
class CollectionController extends Controller
{
    public function __construct(private readonly Presenter $present, private readonly RarityResolver $rarity) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'set' => ['nullable', 'string', 'max:30'],
            'state' => ['nullable', 'in:unseen,seen,collected'],
            'rarity' => ['nullable', 'string', 'max:20'],
            'family' => ['nullable', 'string', 'max:120'],
            'habitat' => ['nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();
        $set = CardSet::query()->where('is_active', true)
            ->where('code', $f['set'] ?? config('fishdex.catalogue.default_card_set'))
            ->first()
            ?? CardSet::query()->where('is_active', true)->orderBy('id')->firstOrFail();

        $cards = $set->cards()->where('variant', 'standard')->where('is_active', true)
            ->with(['species.names', 'species.family', 'species.habitats', 'species.images', 'artwork', 'set'])
            ->orderBy('card_number')->get();

        $entries = $user->collection()->whereIn('species_id', $cards->pluck('species_id'))
            ->with(['raritySnapshot', 'coverMedia'])->get()->keyBy('species_id');

        $items = $cards->map(function (FishCard $card) use ($entries, $user) {
            $entry = $entries->get($card->species_id);
            $state = $entry?->state->value ?? 'unseen';
            $tier = $this->rarity->tierFor($card->species_id, $user->country_id);

            return [
                'card' => $this->present->card($card),
                'state' => $state,
                'rarity' => $this->present->rarity($tier),
                // Unseen cards reveal nothing but a silhouette and the number.
                'species' => $state === 'unseen'
                    ? ['id' => $card->species->public_id, 'family' => $card->species->family->scientific_name]
                    : $this->present->speciesItem($card->species, $user->country_id, $state),
                'collection' => $entry ? $this->present->collectionSummary($entry) : null,
                '_family' => $card->species->family->scientific_name,
                '_habitats' => $card->species->habitats->pluck('code')->all(),
            ];
        });

        $filtered = $items
            ->when($f['state'] ?? null, fn ($c, $v) => $c->where('state', $v))
            ->when($f['rarity'] ?? null, fn ($c, $v) => $c->filter(fn ($i) => ($i['rarity']['code'] ?? null) === $v))
            ->when($f['family'] ?? null, fn ($c, $v) => $c->where('_family', $v))
            ->when($f['habitat'] ?? null, fn ($c, $v) => $c->filter(fn ($i) => in_array($v, $i['_habitats'], true)))
            ->map(fn ($i) => array_diff_key($i, array_flip(['_family', '_habitats'])))
            ->values();

        return response()->json([
            'data' => $filtered,
            'meta' => [
                'set' => ['id' => $set->public_id, 'code' => $set->code, 'name' => $set->name],
                'total' => $items->count(),
                'collected' => $items->where('state', 'collected')->count(),
                'seen' => $items->where('state', 'seen')->count(),
            ],
        ]);
    }

    public function show(Request $request, string $speciesId): JsonResponse
    {
        $user = $request->user();
        $species = FishSpecies::query()->where('public_id', $speciesId)->firstOrFail();
        $entry = $this->entry($request, $species);

        $encounters = FishEncounter::query()->where('user_id', $user->id)->where('species_id', $species->id)
            ->where('status', 'confirmed')->latest('caught_at')->limit(20)->get();

        return response()->json(['data' => [
            'species' => $this->present->speciesItem($species->load(['names', 'images', 'family']), $user->country_id, $entry->state->value),
            'card' => $entry->card ? $this->present->card($entry->card) : null,
            'collection' => $this->present->collectionSummary($entry->load(['raritySnapshot', 'coverMedia'])),
            'encounters' => $encounters->map(fn ($e) => $this->present->encounter($e)),
        ]]);
    }

    public function update(Request $request, string $speciesId): JsonResponse
    {
        $data = $request->validate([
            'is_favorite' => ['sometimes', 'boolean'],
            'cover_media_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        $species = FishSpecies::query()->where('public_id', $speciesId)->firstOrFail();
        $entry = $this->entry($request, $species);

        if (array_key_exists('cover_media_id', $data)) {
            $mediaId = null;
            if ($data['cover_media_id']) {
                // Cover must be a photo from this user's encounter of this same species.
                $mediaId = Media::query()->where('public_id', $data['cover_media_id'])->where('user_id', $request->user()->id)
                    ->whereExists(fn ($q) => $q->from('encounter_media')
                        ->join('fish_encounters', 'fish_encounters.id', '=', 'encounter_media.encounter_id')
                        ->whereColumn('encounter_media.media_id', 'media.id')
                        ->where('fish_encounters.species_id', $species->id)
                        ->whereNull('fish_encounters.deleted_at'))
                    ->value('id') ?? throw Problem::make(422, 'validation_failed', 'That photo is not from this species.', ['errors' => ['cover_media_id' => ['Invalid photo.']]]);
            }
            $entry->cover_media_id = $mediaId;
        }
        if (array_key_exists('is_favorite', $data)) {
            $entry->is_favorite = $data['is_favorite'];
        }
        $entry->save();

        return response()->json(['data' => $this->present->collectionSummary($entry->fresh(['raritySnapshot', 'coverMedia']))]);
    }

    public function cardSets(Request $request): JsonResponse
    {
        $user = $request->user();
        $owned = $user->collection()->where('state', 'collected')->pluck('species_id');

        $sets = CardSet::query()->where('is_active', true)->orderBy('id')->get()->map(fn (CardSet $s) => [
            'id' => $s->public_id,
            'code' => $s->code,
            'name' => $s->name,
            'released_at' => $s->released_at?->toDateString(),
            'total' => $total = $s->cards()->where('variant', 'standard')->where('is_active', true)->count(),
            'collected' => $s->cards()->where('variant', 'standard')->whereIn('species_id', $owned)->count(),
        ]);

        return response()->json(['data' => $sets]);
    }

    public function card(string $id): JsonResponse
    {
        $card = FishCard::query()->where('public_id', $id)->where('is_active', true)->firstOrFail();

        return response()->json(['data' => $this->present->card($card)]);
    }

    private function entry(Request $request, FishSpecies $species): UserSpeciesCollection
    {
        return $request->user()->collection()->where('species_id', $species->id)->with('card')->firstOrFail();
    }
}
