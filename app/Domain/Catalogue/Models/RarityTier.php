<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;

class RarityTier extends Model
{
    protected $fillable = ['code', 'sort_order', 'name_en', 'name_ms', 'color_hex', 'foil_effect', 'reveal_animation'];

    public function localizedName(string $locale): string
    {
        return $locale === 'ms' ? $this->name_ms : $this->name_en;
    }
}
