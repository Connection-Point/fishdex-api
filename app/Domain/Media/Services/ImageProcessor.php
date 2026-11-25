<?php

namespace App\Domain\Media\Services;

use App\Domain\Shared\Support\Problem;
use GdImage;

/**
 * GD-based image handling (no extra dependency):
 *  - verifies real image type from bytes (not the client's claimed MIME),
 *  - decodes and fully re-encodes (neutralises payloads, strips ALL metadata incl. GPS),
 *  - auto-orients from EXIF, builds variants, computes a 64-bit dHash.
 */
class ImageProcessor
{
    /**
     * @return array{image: GdImage, mime: string, width: int, height: int, exif_taken_at: ?string, exif_has_gps: bool}
     */
    public function inspect(string $bytes): array
    {
        $cfg = config('fishdex.media');

        if (strlen($bytes) > $cfg['max_bytes']) {
            throw Problem::make(413, 'image_rejected', 'Image is larger than 8 MB.', ['reason' => 'too_large']);
        }

        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? null;
        if (! $info || ! in_array($mime, $cfg['allowed_mimes'], true)) {
            throw Problem::make(422, 'image_rejected', 'Unsupported image. Upload a JPEG, PNG or WebP photo.', ['reason' => 'unsupported_type']);
        }

        [$width, $height] = $info;
        if (min($width, $height) < $cfg['min_short_side']) {
            throw Problem::make(422, 'image_rejected', 'Image is too small to identify a fish.', ['reason' => 'too_small']);
        }
        if (max($width, $height) > 12000) {
            throw Problem::make(422, 'image_rejected', 'Image dimensions are too large.', ['reason' => 'too_large_dimensions']);
        }

        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            throw Problem::make(422, 'image_rejected', 'Image could not be decoded.', ['reason' => 'corrupt']);
        }

        $exif = $mime === 'image/jpeg' && function_exists('exif_read_data')
            ? (@exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes)) ?: [])
            : [];

        $image = $this->orient($image, (int) ($exif['Orientation'] ?? 1));

        return [
            'image' => $image,
            'mime' => $mime,
            'width' => imagesx($image),
            'height' => imagesy($image),
            'exif_taken_at' => $this->exifDate($exif['DateTimeOriginal'] ?? null),
            'exif_has_gps' => isset($exif['GPSLatitude']) || isset($exif['GPSLongitude']),
        ];
    }

    /** Resize so the long side is at most $longSide (never upscales) and encode. */
    public function encode(GdImage $image, int $longSide, string $format, int $quality): string
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1.0, $longSide / max($w, $h));
        $resized = $scale < 1.0 ? imagescale($image, (int) round($w * $scale), (int) round($h * $scale), IMG_BICUBIC) : $image;

        ob_start();
        match ($format) {
            'webp' => imagewebp($resized, null, $quality),
            'png' => imagepng($resized),
            default => imagejpeg($this->flatten($resized), null, $quality),
        };

        return (string) ob_get_clean();
    }

    /** 64-bit difference hash for near-duplicate detection. */
    public function dhash(GdImage $image): int
    {
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));

        $hash = 0;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $hash = ($hash << 1) | ($this->luma($small, $x, $y) > $this->luma($small, $x + 1, $y) ? 1 : 0);
            }
        }

        return $hash;
    }

    private function luma(GdImage $img, int $x, int $y): float
    {
        $rgb = imagecolorat($img, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    private function flatten(GdImage $image): GdImage
    {
        // JPEG has no alpha: paint onto white so transparent PNGs don't turn black.
        $out = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopy($out, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $out;
    }

    private function exifDate(?string $value): ?string
    {
        if (! $value || ! preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}:\d{2}:\d{2})$/', $value, $m)) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}";
    }
}
