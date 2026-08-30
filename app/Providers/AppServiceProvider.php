<?php

namespace App\Providers;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Policies\ApiKeyPolicy;
use App\Policies\MerchantPolicy;
use App\Services\ApiKeys\ApiRequestContext;
use App\Services\Merchants\CurrentMerchant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewFactory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentMerchant::class);

        // Request-scoped, not a singleton: the API context must never leak
        // an authenticated merchant across requests in long-running workers.
        // Scoped bindings are flushed between requests/jobs (Octane, queue
        // workers), while CurrentMerchant is a dashboard session service.
        $this->app->scoped(ApiRequestContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Merchant::class, MerchantPolicy::class);
        Gate::policy(ApiKey::class, ApiKeyPolicy::class);

        View::composer(['dashboard', 'layouts.app'], function (ViewFactory $view) {
            if (auth()->check()) {
                $view->with('currentMerchant', app(CurrentMerchant::class)->get());
            }
        });
    }
}
