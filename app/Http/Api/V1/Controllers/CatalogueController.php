<?php

namespace App\Http\Api\V1\Controllers;

use App\Domain\Cards\Services\CardResolver;
use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\TaxonFamily;
use App\Domain\Catalogue\Queries\SpeciesSearchQuery;
use App\Domain\Catalogue\Services\CatalogueSnapshot;
use App\Http\Api\V1\Presenters\Presenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogueController extends Controller
{
    public function __construct(private readonly Presenter $present, private readonly CardResolver $cards) {}

    public function index(Request $request, SpeciesSearchQuery $search): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'family' => ['nullable', 'string', 'max:120'],
            'habitat' => ['nullable', 'string', 'max:40'],
            'water_type' => ['nullable', 'in:freshwater,brackish,marine'],
            'origin' => ['nullable', 'in:native,endemic,introduced,invasive,uncertain'],
            'rarity' => ['nullable', 'string', 'max:20'],
            'region' => ['nullable', 'string', 'max:10'],
            'sort' => ['nullable', 'in:name,card_number'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $request->user();
        $page = $search->build($user->country_id, $filters)
            ->with(['names', 'family', 'images'])
            ->cursorPaginate($filters['limit'] ?? 20);

        $ids = $page->getCollection()->pluck('id')->all();
        $states = $user->collection()->whereIn('species_id', $ids)->get(['species_id', 'state'])->keyBy('species_id');
        $cards = $this->cards->forSpeciesMany($ids, $user->country_id);

        return response()->json([
            'data' => $page->getCollection()->map(fn (FishSpecies $s) => $this->present->speciesItem(
                $s, $user->country_id, $states->get($s->id)?->state->value ?? 'unseen', $cards->get($s->id),
            )),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'limit' => $page->perPage()],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $species = FishSpecies::query()->published()->where('public_id', $id)
            ->with(['names', 'family', 'habitats', 'regions', 'countryStatuses', 'conservationAssessments', 'images', 'translations'])
            ->firstOrFail();

        $entry = $user->collection()->where('species_id', $species->id)->with(['raritySnapshot', 'coverMedia'])->first();

        return response()->json(['data' => $this->present->speciesDetail(
            $species, $user->country_id, $entry, $this->cards->forSpecies($species->id, $user->country_id),
        )]);
    }

    public function families(Request $request): JsonResponse
    {
        $families = TaxonFamily::query()
            ->whereHas('species', fn ($q) => $q->published())
            ->withCount(['species' => fn ($q) => $q->published()])
            ->orderBy('scientific_name')
            ->get();

        return response()->json(['data' => $families->map(fn ($f) => [
            'scientific_name' => $f->scientific_name,
            'order' => $f->order_name,
            'common_name' => app()->getLocale() === 'ms' ? ($f->common_name_ms ?? $f->common_name_en) : $f->common_name_en,
            'species_count' => $f->species_count,
        ])]);
    }

    public function snapshot(Request $request, CatalogueSnapshot $snapshot): JsonResponse
    {
        $countryId = $request->user()->country_id;
        $etag = '"'.$snapshot->version($countryId).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)->setEtag(trim($etag, '"'));
        }

        return response()->json(['data' => $snapshot->build($countryId)])
            ->setEtag(trim($etag, '"'))
            ->header('Cache-Control', 'private, max-age=0, must-revalidate');
    }
}
