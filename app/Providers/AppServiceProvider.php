<?php

namespace App\Providers;

use App\Domain\Admin\AdminAuditObserver;
use App\Domain\Admin\Models\Admin;
use App\Domain\Cards\Models\CardSet;
use App\Domain\Cards\Models\FishCard;
use App\Domain\Catalogue\Models\ConservationAssessment;
use App\Domain\Catalogue\Models\FishSpecies;
use App\Domain\Catalogue\Models\ReferenceImage;
use App\Domain\Catalogue\Models\SpeciesCountryStatus;
use App\Domain\Catalogue\Models\SpeciesName;
use App\Domain\Catalogue\Models\SpeciesRarity;
use App\Domain\Catalogue\Models\SpeciesSynonym;
use App\Domain\Catalogue\Models\TaxonFamily;
use App\Domain\Catalogue\Services\RarityResolver;
use App\Domain\Collection\Listeners\RefreshUserStats;
use App\Domain\Encounter\Events\EncounterChanged;
use App\Domain\Encounter\Events\EncounterDecided;
use App\Domain\Encounter\Models\FishEncounter;
use App\Domain\Encounter\Models\FishingTrip;
use App\Domain\Encounter\Policies\OwnedByUserPolicy;
use App\Domain\Identification\Events\ScanCompleted;
use App\Domain\Identification\Models\IdentificationRequest;
use App\Domain\Identification\Services\CandidateMatcher;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Listeners\NotifyScanCompleted;
use App\Domain\Review\Models\ReviewItem;
use App\Domain\Settings\Models\AppSetting;
use App\Domain\Settings\Services\SettingsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(RarityResolver::class);
        $this->app->scoped(CandidateMatcher::class);
    }

    public function boot(): void
    {
        // Catch N+1 queries and mass-assignment typos outside production.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'user' => User::class,
            'admin' => Admin::class,
        ]);

        // Ownership policies: users only ever see their own scans, encounters and trips.
        Gate::policy(IdentificationRequest::class, OwnedByUserPolicy::class);
        Gate::policy(FishEncounter::class, OwnedByUserPolicy::class);
        Gate::policy(FishingTrip::class, OwnedByUserPolicy::class);

        // Audit trail for staff changes (doc 10 §2).
        foreach ([
            FishSpecies::class, SpeciesName::class,
            SpeciesSynonym::class, SpeciesRarity::class,
            ConservationAssessment::class, ReferenceImage::class,
            SpeciesCountryStatus::class, TaxonFamily::class,
            CardSet::class, FishCard::class,
            AppSetting::class, ReviewItem::class,
            User::class, Admin::class,
        ] as $audited) {
            $audited::observe(AdminAuditObserver::class);
        }

        Event::listen(EncounterDecided::class, RefreshUserStats::class);
        Event::listen(EncounterChanged::class, RefreshUserStats::class);
        Event::listen(ScanCompleted::class, NotifyScanCompleted::class);

        ResetPassword::createUrlUsing(fn ($user, string $token) => config('app.frontend_url', config('app.url'))
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));

        $this->rateLimits();
    }

    private function rateLimits(): void
    {
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));

        RateLimiter::for('auth', fn (Request $r) => [
            Limit::perMinute(5)->by('auth:'.strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(30)->by('auth-ip:'.$r->ip()),
        ]);

        RateLimiter::for('scans', fn (Request $r) => Limit::perMinute(
            (int) app(SettingsService::class)->get('quotas.per_minute', 6)
        )->by('scans:'.$r->user()?->id));

        RateLimiter::for('uploads', fn (Request $r) => Limit::perHour(30)->by('uploads:'.$r->user()?->id));
    }
}
