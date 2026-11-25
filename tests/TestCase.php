<?php

namespace Tests;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Identification\Providers\FakeProvider;
use App\Domain\Identity\Models\User;
use Database\Seeders\MalaysiaSampleCatalogueSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seedCatalogue = true;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fishdex.ai.provider' => 'fake',
            'fishdex.ai.fallback_provider' => null,
            'fishdex.ai.tier' => 'free',
            'fishdex.review.random_sample_ratio' => 0,
        ]);
        Storage::fake('media');
        Storage::fake('assets');
        FakeProvider::reset();

        $this->seed(ReferenceDataSeeder::class);
        if ($this->seedCatalogue) {
            $this->seed(MalaysiaSampleCatalogueSeeder::class);
        }
    }

    protected function species(string $scientific): FishSpecies
    {
        return FishSpecies::query()->where('scientific_name', $scientific)->firstOrFail();
    }

    protected function angler(array $attributes = []): User
    {
        return User::factory()->consented()->veteran()->create($attributes);
    }

    /** A unique, valid JPEG (random pixels → unique sha256 unless $seed is reused). */
    protected function photo(?int $seed = null, int $w = 800, int $h = 600): UploadedFile
    {
        mt_srand($seed ?? random_int(1, PHP_INT_MAX));
        $img = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 400; $i++) {
            $color = imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledrectangle($img, mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, $w), mt_rand(0, $h), $color);
        }
        mt_srand();
        ob_start();
        imagejpeg($img, null, 90);

        return UploadedFile::fake()->createWithContent('fish.jpg', ob_get_clean());
    }

    protected function scan(User $user, ?UploadedFile $photo = null, array $extra = []): TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post('/api/v1/scans', array_merge([
                'image' => $photo ?? $this->photo(),
                'client_uuid' => (string) Str::uuid(),
                'captured_at' => now()->subMinutes(5)->toIso8601String(),
            ], $extra), ['Accept' => 'application/json']);
    }

    protected function confirm(User $user, string $scanId, string $choice = 'species', ?string $speciesId = null): TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/scans/{$scanId}/confirmation", array_filter(['choice' => $choice, 'species_id' => $speciesId]));
    }

    protected function decide(User $user, string $encounterId, string $decision): TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/encounters/{$encounterId}/decision", ['decision' => $decision]);
    }

    /** Queue a confident AI answer for the next scan. */
    protected function aiSays(string $scientific, float $confidence = 0.93, array $others = [], array $flags = []): void
    {
        FakeProvider::push(FakeProvider::result(array_merge(
            [['scientific_name' => $scientific, 'common_name' => 'x', 'confidence' => $confidence, 'visible_features' => ['dark band']]],
            $others,
        ), $flags));
    }
}
