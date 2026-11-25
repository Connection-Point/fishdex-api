<?php

namespace App\Domain\Shared\Concerns;

use Illuminate\Support\Str;

/**
 * Internal BIGINT primary keys stay private; a ULID `public_id` is used in
 * every URL and API payload (prevents ID enumeration, sortable by time).
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
