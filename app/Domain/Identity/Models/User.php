<?php

namespace App\Domain\Identity\Models;

use App\Domain\Catalogue\Models\Country;
use App\Domain\Catalogue\Models\Region;
use App\Domain\Collection\Models\UserSpeciesCollection;
use App\Domain\Collection\Models\UserStats;
use App\Domain\Encounter\Models\FishEncounter;
use App\Domain\Encounter\Models\FishingTrip;
use App\Domain\Media\Models\Media;
use App\Domain\Shared\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasPublicId, Notifiable, SoftDeletes;

    protected $fillable = [
        'handle', 'display_name', 'email', 'password', 'country_id', 'home_region_id', 'locale',
        'timezone', 'units', 'default_location_visibility', 'avatar_media_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** Mirror DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'locale' => 'en',
        'timezone' => 'Asia/Kuala_Lumpur',
        'units' => 'metric',
        'default_location_visibility' => 'private',
        'plan' => 'free',
        'status' => 'active',
        'beta_ai_consent' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'beta_ai_consent' => 'boolean',
            'beta_ai_consent_at' => 'datetime',
            'last_active_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function homeRegion(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'home_region_id');
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(FishEncounter::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(FishingTrip::class);
    }

    public function collection(): HasMany
    {
        return $this->hasMany(UserSpeciesCollection::class);
    }

    public function stats(): HasOne
    {
        return $this->hasOne(UserStats::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
