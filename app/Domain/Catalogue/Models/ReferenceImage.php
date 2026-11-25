<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ReferenceImage extends Model
{
    use HasPublicId;

    /** Licences allowed on a commercial app. Non-commercial (NC) and no-derivatives (ND) are rejected. */
    public const ALLOWED_LICENSES = [
        'owned', 'licensed', 'cc0', 'public-domain',
        'cc-by-4.0', 'cc-by-sa-4.0', 'cc-by-3.0', 'cc-by-sa-3.0', 'cc-by-2.5', 'cc-by-sa-2.5',
        'cc-by-2.0', 'cc-by-sa-2.0', 'cc-by-1.0', 'cc-by-sa-1.0',
    ];

    protected $fillable = [
        'species_id', 'kind', 'disk', 'storage_key', 'width', 'height', 'blurhash', 'license', 'attribution',
        'source_url', 'is_verified', 'verified_by', 'verified_at', 'is_primary', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_verified' => 'boolean', 'is_primary' => 'boolean', 'verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $image) {
            if (! in_array($image->license, self::ALLOWED_LICENSES, true)) {
                throw new InvalidArgumentException("Licence '{$image->license}' is not allowed for commercial use.");
            }
            if (str_starts_with($image->license, 'cc-by') && blank($image->attribution)) {
                throw new InvalidArgumentException('CC-BY licences require attribution.');
            }
        });
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(FishSpecies::class, 'species_id');
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->storage_key);
    }
}
