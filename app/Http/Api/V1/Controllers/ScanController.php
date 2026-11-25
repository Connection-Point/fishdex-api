<?php

namespace App\Http\Api\V1\Controllers;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Encounter\Actions\ConfirmIdentification;
use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Identification\Services\InlineScanRunner;
use App\Domain\Identification\Services\ScanService;
use App\Http\Api\V1\Presenters\Presenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function __construct(private readonly Presenter $present) {}

    public function store(Request $request, ScanService $scans): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'max:8192'],
            'client_uuid' => ['required', 'uuid'],
            'captured_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(10)->toIso8601String()],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'encounter_type' => ['nullable', 'in:catch,observation'],
            'trip_id' => ['nullable', 'string', 'size:26'],
            'region_code' => ['nullable', 'string', 'max:10'],
        ]);

        $scan = $scans->create($request->user(), $request->file('image'), $data);

        return response()->json(['data' => $this->present->scan($scan)], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string'], 'limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $page = $request->user()->hasMany(IdentificationRequest::class)
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('limit', 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($s) => $this->present->scan($s)),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }

    public function show(Request $request, string $scanId, InlineScanRunner $inline): JsonResponse
    {
        return response()->json(['data' => $this->present->scan($inline->advance($this->find($request, $scanId)))]);
    }

    public function confirm(Request $request, string $scanId, ConfirmIdentification $confirm): JsonResponse
    {
        $data = $request->validate([
            'choice' => ['required', 'in:species,not_listed,unsure'],
            'species_id' => ['required_if:choice,species', 'nullable', 'string', 'size:26'],
        ]);

        $scan = $this->find($request, $scanId);
        $species = $data['choice'] === 'species'
            ? FishSpecies::query()->published()->where('public_id', $data['species_id'])->first()
            : null;

        $encounter = $confirm->handle($scan, $data['choice'], $species);

        return response()->json(['data' => [
            'encounter' => $this->present->encounter($encounter, detail: true),
            'collect_eligible' => $encounter->collect_eligible,
            'legal_notice' => $this->present->legalNotice($encounter),
        ]], 201);
    }

    private function find(Request $request, string $scanId): IdentificationRequest
    {
        // Owner-scoped lookup: other users' scans are simply "not found".
        return IdentificationRequest::query()
            ->where('user_id', $request->user()->id)
            ->where('public_id', $scanId)
            ->firstOrFail();
    }
}
