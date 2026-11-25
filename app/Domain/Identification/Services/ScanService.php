<?php

namespace App\Domain\Identification\Services;

use App\Domain\Catalogue\Models\Region;
use App\Domain\Encounter\Models\FishingTrip;
use App\Domain\Identification\Jobs\RunIdentification;
use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Services\MediaStore;
use App\Domain\Settings\Services\SettingsService;
use App\Domain\Shared\Enums\ScanStatus;
use App\Domain\Shared\Support\Geohash;
use App\Domain\Shared\Support\Problem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Steps 1–3 of the AI workflow: accept, validate, store, enqueue. */
class ScanService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ScanQuota $quota,
        private readonly AiBudgetGuard $budget,
        private readonly MediaStore $media,
    ) {}

    /**
     * @param  array{client_uuid: string, captured_at: string, lat?: ?float, lng?: ?float, accuracy_m?: ?float, encounter_type?: ?string, trip_id?: ?string, region_code?: ?string}  $data
     */
    public function create(User $user, UploadedFile $image, array $data): IdentificationRequest
    {
        // Offline sync replays: same client_uuid returns the original scan.
        $existing = IdentificationRequest::query()->where('user_id', $user->id)->where('client_uuid', $data['client_uuid'])->first();
        if ($existing) {
            return $existing;
        }

        if ($this->settings->aiTier() === 'free' && ! $user->beta_ai_consent) {
            throw Problem::make(403, 'ai_consent_required', 'Please accept the beta AI notice before scanning.');
        }

        if (! $this->budget->allowsNewScans()) {
            throw Problem::make(503, 'ai_temporarily_unavailable', 'Fish identification is paused right now. Your photo is safe on your phone — try again later.', [], ['Retry-After' => 900]);
        }

        $quota = $this->quota->status($user);
        if ($quota['remaining'] <= 0) {
            throw Problem::make(429, 'scan_quota_exceeded', 'You have used all your scans for today.', ['quota' => $quota]);
        }

        $media = $this->media->storeUpload($user, $image, 'scan');

        $location = null;
        $geohash = null;
        if (isset($data['lat'], $data['lng'])) {
            $location = ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng'], 'accuracy_m' => $data['accuracy_m'] ?? null];
            $geohash = Geohash::encode((float) $data['lat'], (float) $data['lng'], 5);
        }

        $regionId = null;
        if (! empty($data['region_code'])) {
            $regionId = Region::query()->where('country_id', $user->country_id)->where('code', $data['region_code'])->value('id');
        }
        $regionId ??= $user->home_region_id;

        $tripId = null;
        if (! empty($data['trip_id'])) {
            $tripId = FishingTrip::query()->where('user_id', $user->id)->where('public_id', $data['trip_id'])->value('id');
        }

        $scan = DB::transaction(function () use ($user, $media, $data, $location, $geohash, $regionId, $tripId) {
            $scan = IdentificationRequest::create([
                'user_id' => $user->id,
                'media_id' => $media->id,
                'client_uuid' => $data['client_uuid'],
                'country_id' => $user->country_id,
                'region_id' => $regionId,
                'encounter_type' => $data['encounter_type'] ?? 'catch',
                'trip_id' => $tripId,
                'captured_at' => $data['captured_at'],
                'location_precise' => $location,
                'geohash5' => $geohash,
                'status' => ScanStatus::Queued,
                'ai_tier' => $this->settings->aiTier(),
                'queued_at' => now(),
            ]);

            DB::table('user_scan_usage')->upsert(
                [['user_id' => $user->id, 'period_date' => now($user->timezone)->toDateString(), 'scans_count' => 1, 'created_at' => now(), 'updated_at' => now()]],
                ['user_id', 'period_date'],
                ['scans_count' => DB::raw('user_scan_usage.scans_count + 1'), 'updated_at' => now()],
            );

            return $scan;
        });

        if (! config('fishdex.runtime.inline_scans')) {
            RunIdentification::dispatch($scan->id)->afterCommit(); // otherwise the status poll runs it (InlineScanRunner)
        }

        return $scan->refresh();
    }
}
