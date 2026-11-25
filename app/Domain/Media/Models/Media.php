<?php

namespace App\Domain\Media\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    use HasPublicId;

    protected $table = 'media';

    protected $fillable = [
        'public_id', 'user_id', 'purpose', 'disk', 'storage_key', 'variants', 'mime', 'bytes', 'width', 'height', 'sha256',
        'phash', 'exif_taken_at', 'exif_has_gps', 'status', 'reject_reason', 'purge_after',
    ];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'exif_taken_at' => 'datetime',
            'exif_has_gps' => 'boolean',
            'purge_after' => 'datetime',
            'phash' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function variantKey(string $variant): string
    {
        return $this->variants[$variant] ?? $this->storage_key;
    }
}
