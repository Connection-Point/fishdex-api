<?php

use App\Http\Api\V1\Controllers\AppConfigController;
use App\Http\Api\V1\Controllers\AuthController;
use App\Http\Api\V1\Controllers\CatalogueController;
use App\Http\Api\V1\Controllers\CollectionController;
use App\Http\Api\V1\Controllers\EncounterController;
use App\Http\Api\V1\Controllers\MeController;
use App\Http\Api\V1\Controllers\ScanController;
use App\Http\Api\V1\Controllers\TripController;
use Illuminate\Support\Facades\Route;

/*
| FishDex REST API v1 — prefix /api/v1 (see docs/07-api-spec.md)
*/

Route::get('health', [AppConfigController::class, 'health']);
Route::get('app/config', [AppConfigController::class, 'show']);

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('google', [AuthController::class, 'google']);
    Route::post('password/forgot', [AuthController::class, 'forgotPassword']);
    Route::post('password/reset', [AuthController::class, 'resetPassword']);
});

Route::middleware(['auth:sanctum', 'touch', 'throttle:api'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::post('auth/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:3,10');

    // Profile & devices
    Route::get('me', [MeController::class, 'show']);
    Route::patch('me', [MeController::class, 'update']);
    Route::delete('me', [MeController::class, 'destroy']);
    Route::put('me/avatar', [MeController::class, 'avatar'])->middleware('throttle:uploads');
    Route::post('me/ai-consent', [MeController::class, 'acceptAiConsent']);
    Route::get('me/stats', [MeController::class, 'stats']);
    Route::post('me/devices', [MeController::class, 'registerDevice']);
    Route::delete('me/devices/{deviceUuid}', [MeController::class, 'removeDevice']);

    // Catalogue
    Route::get('species', [CatalogueController::class, 'index']);
    Route::get('species/{id}', [CatalogueController::class, 'show']);
    Route::get('families', [CatalogueController::class, 'families']);
    Route::get('catalogue/snapshot', [CatalogueController::class, 'snapshot']);

    // Scans (AI identification)
    Route::post('scans', [ScanController::class, 'store'])->middleware(['throttle:scans', 'throttle:uploads', 'idempotent']);
    Route::get('scans', [ScanController::class, 'index']);
    Route::get('scans/{scan}', [ScanController::class, 'show']);
    Route::post('scans/{scan}/confirmation', [ScanController::class, 'confirm'])->middleware('idempotent');

    // Encounters (journal)
    Route::get('encounters', [EncounterController::class, 'index']);
    Route::post('encounters', [EncounterController::class, 'store'])->middleware('idempotent');
    Route::get('encounters/{id}', [EncounterController::class, 'show']);
    Route::patch('encounters/{id}', [EncounterController::class, 'update']);
    Route::delete('encounters/{id}', [EncounterController::class, 'destroy']);
    Route::post('encounters/{id}/decision', [EncounterController::class, 'decide'])->middleware('idempotent');
    Route::post('encounters/{id}/media', [EncounterController::class, 'addMedia'])->middleware('throttle:uploads');
    Route::post('encounters/{id}/dispute', [EncounterController::class, 'dispute']);

    // Trips
    Route::get('trips', [TripController::class, 'index']);
    Route::post('trips', [TripController::class, 'store']);
    Route::get('trips/{id}', [TripController::class, 'show']);
    Route::patch('trips/{id}', [TripController::class, 'update']);
    Route::delete('trips/{id}', [TripController::class, 'destroy']);

    // Collection & cards
    Route::get('collection', [CollectionController::class, 'index']);
    Route::get('collection/{speciesId}', [CollectionController::class, 'show']);
    Route::patch('collection/{speciesId}', [CollectionController::class, 'update']);
    Route::get('card-sets', [CollectionController::class, 'cardSets']);
    Route::get('cards/{id}', [CollectionController::class, 'card']);
});
