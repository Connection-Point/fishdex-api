<?php

namespace App\Domain\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;

class Habitat extends Model
{
    protected $fillable = ['code', 'name_en', 'name_ms'];

    public function localizedName(string $locale): string
    {
        return $locale === 'ms' ? $this->name_ms : $this->name_en;
    }
}
