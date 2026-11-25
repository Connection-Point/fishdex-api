<?php

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value', 'updated_by_admin_id'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
