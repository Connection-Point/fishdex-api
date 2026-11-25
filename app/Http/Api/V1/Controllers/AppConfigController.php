<?php

namespace App\Http\Api\V1\Controllers;

use App\Domain\Catalogue\Models\Country;
use App\Domain\Catalogue\Models\Habitat;
use App\Domain\Catalogue\Models\RarityTier;
use App\Domain\Catalogue\Services\CatalogueSnapshot;
use App\Domain\Identification\Services\AiBudgetGuard;
use App\Domain\Identification\Services\AiCapacity;
use App\Domain\Settings\Services\SettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AppConfigController extends Controller
{
    public function show(SettingsService $settings, CatalogueSnapshot $snapshot, AiBudgetGuard $budget, AiCapacity $capacity): JsonResponse
    {
        $locale = app()->getLocale();
        $country = Country::byIso(config('fishdex.default_country'));

        return response()->json(['data' => [
            'min_supported_version' => $settings->get('min_supported_app_version'),
            'country' => $country->iso2,
            'catalogue_version' => $snapshot->version($country->id),
            'ai' => [
                'tier' => $settings->aiTier(),
                'scanning_available' => $budget->allowsNewScans(),
                'consent_required' => $settings->aiTier() === 'free',
                'next_capacity_reset' => $settings->aiTier() === 'free' ? $capacity->nextDailyReset()->toIso8601String() : null,
            ],
            'rarity_tiers' => RarityTier::query()->orderBy('sort_order')->get()->map(fn ($t) => [
                'code' => $t->code, 'name' => $t->localizedName($locale), 'color' => $t->color_hex,
                'foil' => $t->foil_effect, 'reveal_animation' => $t->reveal_animation, 'sort_order' => $t->sort_order,
            ]),
            'habitats' => Habitat::query()->orderBy('id')->get()->map(fn ($h) => ['code' => $h->code, 'name' => $h->localizedName($locale)]),
            'regions' => $country->regions()->orderBy('name')->get(['code', 'name', 'type']),
            'card_sets' => DB::table('card_sets')->where('is_active', true)->get(['public_id as id', 'code', 'name', 'total_cards']),
        ]]);
    }

    public function health(): JsonResponse
    {
        DB::select('select 1');

        return response()->json(['status' => 'ok', 'time' => now()->toIso8601String()]);
    }
}
