<?php

namespace App\Domain\Media\Services;

use App\Domain\Media\Models\Media;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Short-lived signed GET URLs for private user photos. */
class SignedUrlFactory
{
    /** @return array{url: string, expires_at: string}|null */
    public function for(?Media $media, string $variant = 'card'): ?array
    {
        if ($media === null || $media->status === 'purged') {
            return null;
        }

        $ttl = config('fishdex.media.signed_url_ttl_minutes')[$variant === 'thumb' ? 'thumb' : 'full'];
        $expires = now()->addMinutes($ttl);

        try {
            $url = Storage::disk($media->disk)->temporaryUrl($media->variantKey($variant), $expires);
        } catch (Throwable) {
            // Drivers without signing (e.g. local without "serve") — dev only.
            $url = Storage::disk($media->disk)->url($media->variantKey($variant));
        }

        return ['url' => $url, 'expires_at' => $expires->toIso8601String()];
    }

    /** @return array<string, array{url: string, expires_at: string}> */
    public function set(?Media $media): array
    {
        if ($media === null) {
            return [];
        }

        return array_filter([
            'thumb' => $this->for($media, 'thumb'),
            'card' => $this->for($media, 'card'),
        ]);
    }
}
