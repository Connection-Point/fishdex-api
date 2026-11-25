<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    protected $fillable = ['user_id', 'device_uuid', 'platform', 'fcm_token', 'app_version', 'os_version', 'last_seen_at'];

    protected $hidden = ['fcm_token'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
