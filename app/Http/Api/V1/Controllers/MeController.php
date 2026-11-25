<?php

namespace App\Http\Api\V1\Controllers;

use App\Domain\Catalogue\Models\Region;
use App\Domain\Collection\Services\StatsCalculator;
use App\Domain\Identification\Services\ScanQuota;
use App\Domain\Identity\Actions\AccountActions;
use App\Domain\Media\Services\MediaStore;
use App\Domain\Shared\Support\Problem;
use App\Http\Api\V1\Presenters\Presenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function __construct(private readonly Presenter $present) {}

    public function show(Request $request, ScanQuota $quota): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => $this->present->user($user) + ['scan_quota' => $quota->status($user)]]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:60'],
            'handle' => ['sometimes', 'string', 'min:3', 'max:30', 'regex:/^[a-zA-Z0-9_]+$/', Rule::unique('users', 'handle')->ignore($user->id)],
            'home_region' => ['sometimes', 'nullable', 'string', 'max:10'],
            'locale' => ['sometimes', 'in:en,ms'],
            'timezone' => ['sometimes', 'timezone:all'],
            'units' => ['sometimes', 'in:metric,imperial'],
            'default_location_visibility' => ['sometimes', 'in:private,region'],
        ]);

        if (array_key_exists('home_region', $data)) {
            $data['home_region_id'] = $data['home_region']
                ? Region::query()->where('country_id', $user->country_id)->where('code', $data['home_region'])->value('id')
                    ?? throw Problem::make(422, 'validation_failed', 'Unknown region.', ['errors' => ['home_region' => ['Unknown region.']]])
                : null;
            unset($data['home_region']);
        }

        $user->fill($data)->save();

        return response()->json(['data' => $this->present->user($user->fresh())]);
    }

    /** Closed-beta notice: images go to Google's unpaid Gemini tier (D-16). */
    public function acceptAiConsent(Request $request): JsonResponse
    {
        $request->user()->forceFill(['beta_ai_consent' => true, 'beta_ai_consent_at' => now()])->save();

        return response()->json(['data' => $this->present->user($request->user())]);
    }

    public function avatar(Request $request, MediaStore $media): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'max:8192']]);

        $stored = $media->storeUpload($request->user(), $request->file('image'), 'avatar');
        $request->user()->forceFill(['avatar_media_id' => $stored->id])->save();

        return response()->json(['data' => $this->present->user($request->user()->fresh())]);
    }

    public function destroy(Request $request, AccountActions $accounts): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'confirmation' => ['required', 'in:DELETE'],
            'password' => [$user->password ? 'required' : 'nullable', 'string'],
        ]);

        if ($user->password && ! Hash::check((string) $request->input('password'), $user->password)) {
            throw Problem::make(422, 'validation_failed', 'Password is incorrect.', ['errors' => ['password' => ['Password is incorrect.']]]);
        }

        $accounts->requestDeletion($user);

        return response()->json(['data' => [
            'status' => 'pending_deletion',
            'purge_after' => now()->addDays(config('fishdex.account_deletion_grace_days'))->toIso8601String(),
        ]], 202);
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'string', 'max:64'],
            'platform' => ['required', 'in:android,ios'],
            'fcm_token' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'os_version' => ['nullable', 'string', 'max:20'],
        ]);

        $request->user()->devices()->updateOrCreate(
            ['device_uuid' => $data['device_uuid']],
            $data + ['last_seen_at' => now()],
        );

        return response()->json(null, 204);
    }

    public function removeDevice(Request $request, string $deviceUuid): JsonResponse
    {
        $request->user()->devices()->where('device_uuid', $deviceUuid)->delete();

        return response()->json(null, 204);
    }

    public function stats(Request $request, StatsCalculator $calculator): JsonResponse
    {
        $user = $request->user();
        $stats = $user->stats ?? $calculator->recalculate($user);
        if ($stats->recalculated_at?->lt(now()->subDay())) {
            $stats = $calculator->recalculate($user);
        }

        $total = $calculator->catalogueSize($user->country_id);

        $bests = $user->collection()->with('species.names')
            ->where(fn ($q) => $q->whereNotNull('best_length_cm')->orWhereNotNull('best_weight_kg'))
            ->orderByDesc('best_length_cm')->limit(10)->get()
            ->map(fn ($e) => [
                'species' => ['id' => $e->species->public_id, 'scientific_name' => $e->species->scientific_name, 'names' => $this->present->names($e->species, $user->country_id)],
                'best_length_cm' => $e->best_length_cm,
                'best_weight_kg' => $e->best_weight_kg,
            ]);

        $recent = $user->encounters()->latest('caught_at')->limit(5)->get()->map(fn ($e) => $this->present->encounter($e));

        return response()->json(['data' => [
            'species_collected' => $stats->species_collected,
            'species_seen' => $stats->species_seen,
            'total_in_catalogue' => $total,
            'completion_pct' => $total ? round($stats->species_collected / $total * 100, 1) : 0,
            'encounters_total' => $stats->encounters_total,
            'release_rate' => $stats->encounters_total ? round($stats->encounters_released / $stats->encounters_total, 3) : null,
            'by_rarity' => $stats->by_rarity ?? [],
            'by_family' => $stats->by_family ?? [],
            'by_habitat' => $stats->by_habitat ?? [],
            'completion_by_set' => $stats->completion_by_set ?? [],
            'personal_bests' => $bests,
            'recent_activity' => $recent,
            'recalculated_at' => $stats->recalculated_at?->toIso8601String(),
        ]]);
    }
}
