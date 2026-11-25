<?php

namespace App\Domain\Encounter\Models;

use App\Domain\Catalogue\Models\Region;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FishingTrip extends Model
{
    use HasPublicId, SoftDeletes;

    protected $fillable = ['user_id', 'title', 'started_at', 'ended_at', 'water_body_type', 'region_id', 'notes', 'client_uuid'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(FishEncounter::class, 'trip_id');
    }
}
