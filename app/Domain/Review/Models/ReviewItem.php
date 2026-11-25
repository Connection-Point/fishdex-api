<?php

namespace App\Domain\Review\Models;

use App\Domain\Admin\Models\Admin;
use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Encounter\Models\FishEncounter;
use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Shared\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewItem extends Model
{
    use HasPublicId;

    protected $fillable = [
        'encounter_id', 'identification_request_id', 'reason', 'status', 'resolved_species_id', 'assigned_admin_id',
        'user_comment', 'resolution_notes', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(FishEncounter::class, 'encounter_id')->withTrashed();
    }

    public function identificationRequest(): BelongsTo
    {
        return $this->belongsTo(IdentificationRequest::class);
    }

    public function resolvedSpecies(): BelongsTo
    {
        return $this->belongsTo(FishSpecies::class, 'resolved_species_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }
}
