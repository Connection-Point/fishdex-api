<?php

namespace App\Console\Commands;

use App\Domain\Cards\Models\FishCard;
use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\ReferenceImage;
use App\Domain\Media\Services\ImageProcessor;
use GdImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports FishDex-owned card art from a folder of images named after the species.
 *
 *   php artisan fishdex:import-card-art                                  storage/app/public/Images
 *   php artisan fishdex:import-card-art D:\art --crop-bottom=0.1
 *   php artisan fishdex:import-card-art --dry-run
 *
 * File names are matched against each species' scientific name and every common name / alias,
 * ignoring case, spaces and punctuation: "GiantSnakehead.png", "toman.webp", "Channa_micropeltes.jpg".
 *
 * Each image is cropped (bottom strip removed for generator watermarks, then centre-cropped to the
 * card art window), resized, re-encoded as WebP, stored on the assets disk, marked verified and
 * attached to the species' cards. Re-running replaces the species' previous card art.
 */
class ImportCardArt extends Command
{
    protected $signature = 'fishdex:import-card-art
        {path? : Folder of images (default: storage/app/public/Images)}
        {--crop-bottom= : Fraction of the height to cut from the bottom first (default from config)}
        {--dry-run : Only show which file matches which species}';

    protected $description = 'Import card art images (named by species) into the catalogue';

    public function handle(ImageProcessor $images): int
    {
        $dir = $this->argument('path') ?? storage_path('app/public/Images');
        if (! is_dir($dir)) {
            $this->error("Folder not found: {$dir}");

            return self::FAILURE;
        }

        $cfg = config('fishdex.media.card_art');
        $cropBottom = (float) ($this->option('crop-bottom') ?? $cfg['crop_bottom']);
        if ($cropBottom < 0 || $cropBottom >= 0.5) {
            $this->error('--crop-bottom must be between 0 and 0.5.');

            return self::FAILURE;
        }

        $lookup = $this->nameLookup();
        $files = collect(glob(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'*'))
            ->filter(fn ($f) => is_file($f) && preg_match('/\.(png|jpe?g|webp)$/i', $f))
            ->sort()->values();

        if ($files->isEmpty()) {
            $this->warn('No PNG, JPEG or WebP files in that folder.');

            return self::SUCCESS;
        }

        $done = 0;
        $unmatched = [];
        $seen = [];

        foreach ($files as $file) {
            $name = basename($file);
            $species = $lookup[self::norm(pathinfo($file, PATHINFO_FILENAME))] ?? null;

            if (! $species) {
                $unmatched[] = $name;

                continue;
            }
            if (isset($seen[$species->id])) {
                $this->warn("{$name}: {$species->scientific_name} already matched by {$seen[$species->id]}, skipped.");

                continue;
            }
            $seen[$species->id] = $name;

            if ($this->option('dry-run')) {
                $this->line("{$name} → <info>{$species->scientific_name}</info>");

                continue;
            }

            try {
                $this->import($images, $species, $file, $cropBottom, $cfg);
                $this->line("{$name} → <info>{$species->scientific_name}</info>");
                $done++;
            } catch (Throwable $e) {
                $this->warn("{$name}: {$e->getMessage()}");
            }
        }

        foreach ($unmatched as $name) {
            $this->warn("{$name}: no species with that name. Rename it to the English, Malay or scientific name.");
        }

        if (! $this->option('dry-run')) {
            $this->newLine();
            $this->info("Imported card art for {$done} species.");
        }

        return self::SUCCESS;
    }

    /** @param array{width:int,height:int,quality:int} $cfg */
    private function import(ImageProcessor $images, FishSpecies $species, string $file, float $cropBottom, array $cfg): void
    {
        $inspected = $images->inspect((string) file_get_contents($file));
        $art = self::fit($inspected['image'], $cfg['width'], $cfg['height'], $cropBottom);

        ob_start();
        imagewebp($art, null, $cfg['quality']);
        $webp = (string) ob_get_clean();

        $disk = config('fishdex.media.assets_disk');
        $key = 'species/'.$species->public_id.'/art/'.Str::ulid().'.webp';
        Storage::disk($disk)->put($key, $webp, 'public');

        $old = $species->images()->where('kind', 'card_art')->get();

        DB::transaction(function () use ($species, $disk, $key, $art, $old) {
            $image = ReferenceImage::create([
                'species_id' => $species->id,
                'kind' => 'card_art',
                'disk' => $disk,
                'storage_key' => $key,
                'width' => imagesx($art),
                'height' => imagesy($art),
                'license' => 'owned',
                'attribution' => 'FishDex original artwork (AI-assisted)',
                'is_verified' => true,
                'verified_at' => now(),
                'is_primary' => true,
                'sort_order' => 0,
            ]);

            FishCard::query()->where('species_id', $species->id)
                ->where(fn ($q) => $q->whereNull('artwork_image_id')->orWhereIn('artwork_image_id', $old->pluck('id')))
                ->update(['artwork_image_id' => $image->id]);

            ReferenceImage::query()->whereKey($old->pluck('id'))->delete();
        });

        foreach ($old as $o) {
            Storage::disk($o->disk)->delete($o->storage_key);
        }
    }

    /** Cut the bottom strip, centre-crop to the target aspect ratio, then resize (never upscale). */
    public static function fit(GdImage $src, int $width, int $height, float $cropBottom): GdImage
    {
        $w = imagesx($src);
        $h = (int) floor(imagesy($src) * (1 - $cropBottom));
        $target = $width / $height;

        if ($w / $h > $target) {
            $cw = (int) round($h * $target);
            $ch = $h;
            $x = intdiv($w - $cw, 2);
            $y = 0;
        } else {
            $cw = $w;
            $ch = (int) round($w / $target);
            $x = 0;
            $y = intdiv($h - $ch, 2);
        }

        $scale = min(1.0, $width / $cw);
        $ow = (int) round($cw * $scale);
        $oh = (int) round($ch * $scale);

        $out = imagecreatetruecolor($ow, $oh);
        imagecopyresampled($out, $src, 0, 0, $x, $y, $ow, $oh, $cw, $ch);

        return $out;
    }

    /** @return array<string, FishSpecies> normalised name → species */
    private function nameLookup(): array
    {
        $map = [];
        foreach (FishSpecies::query()->with('names')->get() as $s) {
            $names = $s->names->pluck('name')->push($s->scientific_name);
            foreach ($names as $n) {
                foreach ([$n, preg_replace('/\s*\(.*?\)\s*/', ' ', $n)] as $variant) {
                    $map[self::norm($variant)] ??= $s;
                }
            }
        }

        return $map;
    }

    public static function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($s)));
    }
}
