<?php

namespace Tests\Feature;

use App\Domain\Catalogue\Models\ReferenceImage;
use App\Domain\Catalogue\Services\WikimediaImageSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FetchReferenceImagesTest extends TestCase
{
    private function jpeg(): string
    {
        $img = imagecreatetruecolor(1600, 1000);
        imagefill($img, 0, 0, imagecolorallocate($img, 20, 90, 120));
        ob_start();
        imagejpeg($img, null, 85);

        return ob_get_clean();
    }

    private function page(string $title, string $license, string $artist = '<a href="x">Ali</a>', int $w = 2000, string $mime = 'image/jpeg'): array
    {
        $slug = md5($title);

        return [
            'title' => $title,
            'imageinfo' => [[
                'url' => "https://upload.wikimedia.org/full/{$slug}.jpg",
                'thumburl' => "https://upload.wikimedia.org/thumb/{$slug}.jpg",
                'descriptionurl' => 'https://commons.wikimedia.org/wiki/'.rawurlencode($title),
                'width' => $w, 'height' => 1200, 'mime' => $mime,
                'extmetadata' => [
                    'LicenseShortName' => ['value' => $license],
                    'Artist' => ['value' => $artist],
                ],
            ]],
        ];
    }

    private function fakeCommons(array $categoryPages, array $searchPages = []): void
    {
        Http::fake(function ($request) use ($categoryPages, $searchPages) {
            if (str_contains($request->url(), 'upload.wikimedia.org')) {
                return Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']);
            }
            $isCategory = ($request->data()['generator'] ?? null) === 'categorymembers'
                || str_contains($request->url(), 'generator=categorymembers');

            return Http::response(['query' => ['pages' => $isCategory ? $categoryPages : $searchPages]]);
        });
    }

    public function test_imports_only_commercially_usable_photos_with_attribution(): void
    {
        $this->fakeCommons([
            $this->page('File:Channa micropeltes 1.jpg', 'CC BY-SA 4.0', '<a href="u">Ahmad Faiz</a>'),
            $this->page('File:Channa micropeltes NC.jpg', 'CC BY-NC-SA 4.0'),
            $this->page('File:Channa micropeltes ND.jpg', 'CC BY-ND 2.0'),
            $this->page('File:Channa micropeltes range map.png', 'CC0'),
            $this->page('File:Channa micropeltes tiny.jpg', 'CC0', w: 300),
            $this->page('File:Channa micropeltes 2.jpg', 'Public domain'),
        ]);

        $this->artisan('fishdex:fetch-images', ['--species' => 'Channa micropeltes', '--limit' => 5])->assertSuccessful();

        $images = ReferenceImage::query()->orderBy('sort_order')->get();
        $this->assertSame(['cc-by-sa-4.0', 'public-domain'], $images->pluck('license')->sort()->values()->all());
        $this->assertSame('Ahmad Faiz / Wikimedia Commons', $images->firstWhere('license', 'cc-by-sa-4.0')->attribution);
        $this->assertTrue($images->every(fn ($i) => ! $i->is_verified), 'unverified by default');
        foreach ($images as $i) {
            Storage::disk('assets')->assertExists($i->storage_key);
            $this->assertStringEndsWith('.webp', $i->storage_key);
        }
    }

    public function test_verify_flag_only_trusts_category_matches_and_is_idempotent(): void
    {
        $this->fakeCommons(
            [$this->page('File:Toman.jpg', 'CC BY 2.0')],
            [$this->page('File:Some snakehead.jpg', 'CC BY 4.0')],
        );

        $this->artisan('fishdex:fetch-images', ['--species' => 'Channa micropeltes', '--verify' => true])->assertSuccessful();
        $this->artisan('fishdex:fetch-images', ['--species' => 'Channa micropeltes', '--verify' => true])->assertSuccessful();

        $images = ReferenceImage::all();
        $this->assertCount(2, $images, 'second run imports nothing new');
        $this->assertTrue($images->firstWhere('license', 'cc-by-2.0')->is_verified);
        $this->assertTrue($images->firstWhere('license', 'cc-by-2.0')->is_primary);
        $this->assertFalse($images->firstWhere('license', 'cc-by-4.0')->is_verified, 'search results are never auto-verified');
    }

    public function test_api_shows_only_verified_images(): void
    {
        $this->fakeCommons(
            [$this->page('File:Toman.jpg', 'CC0')],
            [$this->page('File:Unsure.jpg', 'CC0')],
        );
        $this->artisan('fishdex:fetch-images', ['--species' => 'Channa micropeltes', '--verify' => true]);

        $user = $this->angler();
        $toman = $this->species('Channa micropeltes');

        $list = $this->actingAs($user, 'sanctum')->getJson('/api/v1/species?q=toman')->assertOk();
        $this->assertNotNull($list->json('data.0.thumbnail_url'));

        $detail = $this->actingAs($user, 'sanctum')->getJson("/api/v1/species/{$toman->public_id}")->assertOk();
        $this->assertCount(1, $detail->json('data.images'));
        $this->assertSame('cc0', $detail->json('data.images.0.license'));
    }

    public function test_licence_normalisation(): void
    {
        $map = [
            'CC BY-SA 4.0' => 'cc-by-sa-4.0',
            'CC BY 2.5' => 'cc-by-2.5',
            'CC BY-SA 3.0 de' => 'cc-by-sa-3.0',
            'CC0' => 'cc0',
            'Public domain' => 'public-domain',
            'CC BY-NC 4.0' => null,
            'CC BY-NC-SA 2.0' => null,
            'CC BY-ND 4.0' => null,
            'GFDL' => null,
            'Fair use' => null,
        ];

        foreach ($map as $in => $out) {
            $this->assertSame($out, WikimediaImageSource::normalizeLicense($in), $in);
        }
    }
}
