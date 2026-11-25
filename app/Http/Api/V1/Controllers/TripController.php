<?php

namespace App\Http\Api\V1\Controllers;

use App\Domain\Catalogue\Models\Region;
use App\Domain\Encounter\Models\FishingTrip;
use App\Http\Api\V1\Presenters\Presenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function __construct(private readonly Presenter $present) {}

    public function index(Request $request): JsonResponse
    {
        $page = $request->user()->trips()->withCount('encounters')->with('region')
            ->orderByDesc('started_at')->cursorPaginate($request->integer('limit', 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($t) => $this->present->trip($t)),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + ['client_uuid' => ['required', 'uuid'], 'started_at' => ['required', 'date']]);
        $user = $request->user();

        $trip = $user->trips()->where('client_uuid', $data['client_uuid'])->first()
            ?? $user->trips()->create($this->attributes($request, $data));

        return response()->json(['data' => $this->present->trip($trip)], $trip->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->present->trip($this->find($request, $id))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $trip = $this->find($request, $id);
        $data = $request->validate($this->rules() + ['started_at' => ['sometimes', 'date']]);
        $trip->fill($this->attributes($request, $data))->save();

        return response()->json(['data' => $this->present->trip($trip->fresh('region'))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $trip = $this->find($request, $id);
        $trip->encounters()->update(['trip_id' => null]);
        $trip->delete();

        return response()->json(null, 204);
    }

    private function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:120'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'water_body_type' => ['nullable', 'in:river,lake,pond,sea,estuary,other'],
            'region' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function attributes(Request $request, array $data): array
    {
        if (array_key_exists('region', $data)) {
            $data['region_id'] = $data['region']
                ? Region::query()->where('country_id', $request->user()->country_id)->where('code', $data['region'])->value('id')
                : null;
            unset($data['region']);
        }

        return $data;
    }

    private function find(Request $request, string $id): FishingTrip
    {
        return $request->user()->trips()->where('public_id', $id)->withCount('encounters')->firstOrFail();
    }
}
