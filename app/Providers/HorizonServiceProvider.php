<?php

namespace App\Providers;

use App\Domain\Admin\Models\Admin;
use App\Domain\Shared\Enums\AdminRole;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Only super admins signed in to the staff panel (admin guard) may open /horizon.
        Horizon::auth(function ($request) {
            $admin = auth('admin')->user();

            return app()->isLocal()
                || ($admin instanceof Admin && $admin->hasRole(AdminRole::SuperAdmin));
        });
    }
}
