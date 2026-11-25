<?php

namespace Tests\Feature;

use App\Domain\Cards\Models\FishCard;
use App\Domain\Catalogue\Models\ReferenceImage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportCardArtTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('assets');
        $this->dir = sys_get_temp_dir().'/fishdex-art-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** Blue artwork with a red "watermark" strip across the bottom 8%. */
    private function art(string $name, int $w = 1408, int $h = 1760): void
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 20, 60, 160));
        imagefilledrectangle($img, 0, (int) ($h * 0.92), $w, $h, imagecolorallocate($img, 255, 0, 0));
        imagepng($img, "{$this->dir}/{$name}");
    }

    public function test_crops_watermark_fits_card_window_and_attaches_to_card(): void
    {
        $this->art('GiantSnakehead.png');
        $this->art('PeacockBass.png');
        $this->art('Tenggiri.png');          // Malay name
        $this->art('Not A Fish.png');

        $this->artisan('fishdex:import-card-art', ['path' => $this->dir])
            ->expectsOutputToContain('Not A Fish.png: no species')
            ->assertSuccessful();

        $toman = $this->species('Channa micropeltes');
        $art = ReferenceImage::query()->where('species_id', $toman->id)->where('kind', 'card_art')->sole();

        $this->assertSame([1152, 1280], [$art->width, $art->height]);
        $this->assertTrue($art->is_verified);
        $this->assertSame('owned', $art->license);

        $img = imagecreatefromstring(Storage::disk('assets')->get($art->storage_key));
        $this->assertSame([1152, 1280], [imagesx($img), imagesy($img)]);
        $bottom = imagecolorat($img, 576, 1279);
        $this->assertLessThan(120, ($bottom >> 16) & 0xFF, 'watermark strip was cropped away');

        $this->assertSame($art->id, FishCard::query()->where('species_id', $toman->id)->value('artwork_image_id'));
        $this->assertSame(3, ReferenceImage::query()->where('kind', 'card_art')->count());
    }

    public function test_rerun_replaces_previous_art(): void
    {
        $this->art('GiantSnakehead.png');
        $this->artisan('fishdex:import-card-art', ['path' => $this->dir])->assertSuccessful();
        $first = ReferenceImage::query()->where('kind', 'card_art')->sole();

        $this->artisan('fishdex:import-card-art', ['path' => $this->dir])->assertSuccessful();
        $second = ReferenceImage::query()->where('kind', 'card_art')->sole();

        $this->assertNotSame($first->id, $second->id);
        Storage::disk('assets')->assertMissing($first->storage_key);
        Storage::disk('assets')->assertExists($second->storage_key);
        $this->assertSame($second->id, FishCard::query()->where('species_id', $second->species_id)->value('artwork_image_id'));
    }

    public function test_api_prefers_card_art_for_thumbnail_and_card(): void
    {
        $this->art('Haruan.png');
        $this->artisan('fishdex:import-card-art', ['path' => $this->dir])->assertSuccessful();

        $haruan = $this->species('Channa striata');
        $art = ReferenceImage::query()->where('species_id', $haruan->id)->sole();
        ReferenceImage::create([
            'species_id' => $haruan->id, 'kind' => 'reference_photo', 'disk' => 'assets', 'storage_key' => 'x/photo.webp',
            'license' => 'cc0', 'is_verified' => true, 'is_primary' => true, 'sort_order' => -5,
        ]);

        $res = $this->actingAs($this->angler(), 'sanctum')->getJson("/api/v1/species/{$haruan->public_id}")->assertOk();

        $this->assertSame($art->url(), $res->json('data.thumbnail_url'));
        $this->assertSame($art->url(), $res->json('data.card.art_url'));
        $this->assertSame('card_art', $res->json('data.images.0.kind'));
    }

    public function test_seeder_adds_peacock_bass_without_renumbering_series(): void
    {
        $bass = $this->species('Cichla ocellaris');
        $this->assertSame('021', FishCard::query()->where('species_id', $bass->id)->value('card_number'));
        $this->assertSame('Forest snakehead', $this->species('Channa lucius')->names->firstWhere('locale', 'en')->name);
    }
}
