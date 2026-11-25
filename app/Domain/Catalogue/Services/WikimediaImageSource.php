<?php

namespace App\Domain\Catalogue\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Finds commercially usable photos of a species on Wikimedia Commons.
 *
 * Strategy: files in "Category:<Scientific name>" first (curated by Commons editors, reliable),
 * then a full-text file search as a fallback (less reliable → never auto-verified).
 * Only CC0, public domain, CC BY and CC BY-SA files are returned; NC/ND/GFDL-only/fair use are skipped.
 */
class WikimediaImageSource
{
    private const API = 'https://commons.wikimedia.org/w/api.php';

    /** Titles that are usually not a photo of the fish itself. */
    private const SKIP_TITLE = '/\b(map|range|distribution|stamp|logo|coin|label|chart|diagram|skeleton|fossil|menu|dish|cooked|market\s*price)\b/i';

    /**
     * @return list<array{title: string, download_url: string, width: int, height: int, license: string,
     *                     attribution: string, source_url: string, from_category: bool}>
     */
    public function find(string $scientificName, int $limit = 3): array
    {
        $results = $this->query([
            'generator' => 'categorymembers',
            'gcmtitle' => 'Category:'.$scientificName,
            'gcmtype' => 'file',
            'gcmlimit' => 40,
        ], fromCategory: true);

        if (count($results) < $limit) {
            $more = $this->query([
                'generator' => 'search',
                'gsrsearch' => '"'.$scientificName.'" filetype:bitmap',
                'gsrnamespace' => 6,
                'gsrlimit' => 20,
            ], fromCategory: false);

            $seen = array_column($results, 'source_url');
            foreach ($more as $r) {
                if (! in_array($r['source_url'], $seen, true)) {
                    $results[] = $r;
                }
            }
        }

        // Prefer category files, then larger images.
        usort($results, fn ($a, $b) => [$b['from_category'], $b['width']] <=> [$a['from_category'], $a['width']]);

        return array_slice($results, 0, $limit);
    }

    /** Download the (scaled) image bytes. */
    public function download(string $url): string
    {
        return $this->http()->timeout(60)->get($url)->throw()->body();
    }

    /** Map a Commons licence name ("CC BY-SA 4.0", "CC0", "Public domain") to our licence codes. */
    public static function normalizeLicense(?string $short): ?string
    {
        if ($short === null) {
            return null;
        }
        $s = Str::lower(trim(strip_tags($short)));

        if (str_contains($s, 'nc') && preg_match('/\bnc\b/', str_replace('-', ' ', $s))) {
            return null; // non-commercial
        }
        if (preg_match('/\bnd\b/', str_replace('-', ' ', $s))) {
            return null; // no derivatives
        }
        if (str_starts_with($s, 'cc0') || str_contains($s, 'cc-zero')) {
            return 'cc0';
        }
        if (str_contains($s, 'public domain') || $s === 'pd' || str_starts_with($s, 'pd-')) {
            return 'public-domain';
        }
        if (preg_match('/^cc[\s-]by(?<sa>[\s-]sa)?[\s-](?<v>[1-4]\.[05])/', $s, $m)) {
            return 'cc-by'.(! empty($m['sa']) ? '-sa' : '').'-'.$m['v'];
        }

        return null; // GFDL-only, fair use, unknown
    }

    private function query(array $generator, bool $fromCategory): array
    {
        $response = $this->http()->get(self::API, $generator + [
            'action' => 'query',
            'format' => 'json',
            'formatversion' => 2,
            'prop' => 'imageinfo',
            'iiprop' => 'url|size|mime|extmetadata',
            'iiurlwidth' => 1280,
            'iiextmetadatafilter' => 'LicenseShortName|Artist|Credit|AttributionRequired',
        ]);

        if (! $response->successful()) {
            return [];
        }

        $out = [];
        foreach ($response->json('query.pages', []) as $page) {
            $info = $page['imageinfo'][0] ?? null;
            $title = $page['title'] ?? '';
            if (! $info || preg_match(self::SKIP_TITLE, $title)) {
                continue;
            }
            if (! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
                continue;
            }
            if (min((int) ($info['width'] ?? 0), (int) ($info['height'] ?? 0)) < 500) {
                continue;
            }

            $meta = $info['extmetadata'] ?? [];
            $license = self::normalizeLicense($meta['LicenseShortName']['value'] ?? null);
            if ($license === null) {
                continue;
            }

            $artist = trim(html_entity_decode(strip_tags($meta['Artist']['value'] ?? ''), ENT_QUOTES)) ?: 'Unknown author';

            $out[] = [
                'title' => $title,
                'download_url' => $info['thumburl'] ?? $info['url'],
                'width' => (int) $info['width'],
                'height' => (int) $info['height'],
                'license' => $license,
                'attribution' => Str::limit($artist, 200, '').' / Wikimedia Commons',
                'source_url' => $info['descriptionurl'] ?? $info['url'],
                'from_category' => $fromCategory,
            ];
        }

        return $out;
    }

    private function http()
    {
        // Wikimedia asks API clients to identify themselves with a contact.
        return Http::withHeaders([
            'User-Agent' => 'FishDexBot/1.0 ('.config('mail.from.address', 'admin@fishdex.app').')',
        ])->timeout(20)->retry(2, 1000, throw: false);
    }
}
