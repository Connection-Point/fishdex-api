<?php

namespace App\Domain\Collection\Models;

use Illuminate\Database\Eloquent\Model;

class UserStats extends Model
{
    protected $table = 'user_stats';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'species_seen', 'species_collected', 'encounters_total', 'encounters_released', 'by_rarity',
        'by_family', 'by_habitat', 'completion_by_set', 'last_encounter_at', 'recalculated_at',
    ];

    protected function casts(): array
    {
        return [
            'by_rarity' => 'array',
            'by_family' => 'array',
            'by_habitat' => 'array',
            'completion_by_set' => 'array',
            'last_encounter_at' => 'datetime',
            'recalculated_at' => 'datetime',
        ];
    }
}
