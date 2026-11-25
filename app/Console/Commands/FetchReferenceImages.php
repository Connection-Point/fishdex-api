<?php

namespace App\Console\Commands;

use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\ReferenceImage;
use App\Domain\Catalogue\Services\WikimediaImageSource;
use App\Domain\Media\Services\ImageProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports reference photos for catalogue species from Wikimedia Commons (licence-checked).
 *
 *   php artisan fishdex:fetch-images                     all published species, up to 3 photos each
 *   php artisan fishdex:fetch-images --species="Channa micropeltes" --limit=5
 *   php artisan fishdex:fetch-images --verify            mark category-matched photos as verified (beta convenience)
 *
 * Imported photos are UNVERIFIED by default — the app only shows verified images, so a curator
 * reviews them in Admin → Species → Reference images. Photos found by text search (not the
 * species' own Commons category) are never auto-verified.
 */
class FetchReferenceImages extends Command
{
    protected $signature = 'fishdex:fetch-images
        {--species= : Only this scientific name}
        {--limit=3 : Photos per species}
        {--verify : Mark photos from the species\' own Commons category as verified}
        {--missing : Only species with no verified image yet}
        {--dry-run : Show what would be imported}';

    protected $description = 'Import licence-checked reference photos from Wikimedia Commons';

    public function handle(WikimediaImageSource $source, ImageProcessor $images): int
    {
        $disk = config('fishdex.media.assets_disk');
        $limit = max(1, (int) $this->option('limit'));

        $species = FishSpecies::query()->published()
            ->when($this->option('species'), fn ($q, $name) => $q->where('scientific_name', $name))
            ->when($this->option('missing'), fn ($q) => $q->whereDoesntHave('images', fn ($i) => $i->where('is_verified', true)))
            ->orderBy('scientific_name')
            ->get();

        if ($species->isEmpty()) {
            $this->warn('No matching published species.');

            return self::SUCCESS;
        }

        $imported = 0;
        $bar = $this->output->createProgressBar($species->count());

        foreach ($species as $s) {
            $bar->advance();

            try {
                $candidates = $source->find($s->scientific_name, $limit);
            } catch (Throwable $e) {
                $this->newLine();
                $this->warn("{$s->scientific_name}: search failed ({$e->getMessage()})");

                continue;
            }

            if (! $candidates) {
                $this->newLine();
                $this->line("<comment>{$s->scientific_name}</comment>: no commercially usable photos found.");

                continue;
            }

            $hasPrimary = $s->images()->where('is_primary', true)->exists();
            $order = (int) $s->images()->max('sort_order');

            foreach ($candidates as $c) {
                if ($s->images()->where('source_url', $c['source_url'])->exists()) {
                    continue; // already imported
                }

                if ($this->option('dry-run')) {
                    $this->newLine();
                    $this->line("  would import {$c['title']} [{$c['license']}] ".($c['from_category'] ? '(category)' : '(search)'));

                    continue;
                }

                try {
                    $inspected = $images->inspect($source->download($c['download_url']));
                    $webp = $images->encode($inspected['image'], 1280, 'webp', 85);
                    $key = 'species/'.$s->public_id.'/ref/'.Str::ulid().'.webp';
                    Storage::disk($disk)->put($key, $webp, 'public');

                    $verify = $this->option('verify') && $c['from_category'];

                    ReferenceImage::create([
                        'species_id' => $s->id,
                        'kind' => 'reference_photo',
                        'disk' => $disk,
                        'storage_key' => $key,
                        'width' => min($inspected['width'], 1280),
                        'height' => (int) round($inspected['height'] * min(1, 1280 / max($inspected['width'], $inspected['height']))),
                        'license' => $c['license'],
                        'attribution' => $c['attribution'],
                        'source_url' => $c['source_url'],
                        'is_verified' => $verify,
                        'verified_at' => $verify ? now() : null,
                        'is_primary' => ! $hasPrimary && $verify,
                        'sort_order' => ++$order,
                    ]);

                    $hasPrimary = $hasPrimary || $verify;
                    $imported++;
                } catch (Throwable $e) {
                    $this->newLine();
                    $this->warn("  skipped {$c['title']}: {$e->getMessage()}");
                }

                usleep(300_000); // be polite to Wikimedia
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Imported {$imported} photo(s).".($this->option('verify') ? '' : ' Review and verify them in Admin → Species → Reference images.'));
        $this->line('Licences/attribution are stored per image and shown in the app. CC BY-SA photos must keep their attribution.');

        return self::SUCCESS;
    }
}
