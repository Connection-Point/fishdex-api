<?php

namespace App\Domain\Media\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Validates, sanitises and stores a user photo plus its variants in the private bucket.
 * Key layout: u/{user_ulid}/{purpose}/{yyyy}/{mm}/{media_ulid}/{variant}.{ext}  (doc 09)
 */
class MediaStore
{
    public function __construct(private readonly ImageProcessor $images) {}

    public function storeUpload(User $user, UploadedFile $file, string $purpose): Media
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $inspected = $this->images->inspect($bytes);
        $image = $inspected['image'];

        $disk = config('fishdex.media.disk');
        $mediaUlid = (string) Str::ulid();
        $base = sprintf('u/%s/%s/%s/%s', $user->public_id, $purpose, now()->format('Y/m'), $mediaUlid);

        // Original = re-encoded JPEG (metadata stripped), capped at max long side.
        $original = $this->images->encode($image, config('fishdex.media.max_long_side'), 'jpg', 88);
        Storage::disk($disk)->put("{$base}/original.jpg", $original);

        $variants = [];
        foreach (config('fishdex.media.variants') as $name => $v) {
            if ($purpose === 'avatar' && $name !== 'thumb') {
                continue;
            }
            $key = "{$base}/{$name}.{$v['format']}";
            Storage::disk($disk)->put($key, $this->images->encode($image, $v['long_side'], $v['format'], $v['quality']));
            $variants[$name] = $key;
        }

        return Media::create([
            'public_id' => $mediaUlid,
            'user_id' => $user->id,
            'purpose' => $purpose,
            'disk' => $disk,
            'storage_key' => "{$base}/original.jpg",
            'variants' => $variants,
            'mime' => 'image/jpeg',
            'bytes' => strlen($original),
            'width' => $inspected['width'],
            'height' => $inspected['height'],
            // Hash of the *uploaded* bytes: identical re-uploads dedupe even after re-encoding.
            'sha256' => hash('sha256', $bytes),
            'phash' => $this->images->dhash($image),
            'exif_taken_at' => $inspected['exif_taken_at'],
            'exif_has_gps' => $inspected['exif_has_gps'],
            'status' => 'ready',
            'purge_after' => $purpose === 'scan' ? now()->addDays(config('fishdex.media.abandoned_scan_purge_days')) : null,
        ]);
    }

    public function bytes(Media $media, string $variant = 'ai'): string
    {
        return (string) Storage::disk($media->disk)->get($media->variantKey($variant));
    }

    /** Keep the photo: it now belongs to a confirmed/journal encounter. */
    public function retain(Media $media): void
    {
        if ($media->purge_after !== null) {
            $media->forceFill(['purge_after' => null, 'purpose' => 'encounter'])->save();
        }
    }

    public function purge(Media $media): void
    {
        $paths = array_unique(array_merge([$media->storage_key], array_values($media->variants ?? [])));
        Storage::disk($media->disk)->delete($paths);
        $media->forceFill(['status' => 'purged', 'variants' => []])->save();
    }
}
