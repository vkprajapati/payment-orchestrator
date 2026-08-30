<?php

namespace App\Providers;

use App\Models\Merchant;
use App\Policies\MerchantPolicy;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Merchant::class, MerchantPolicy::class);

        View::composer(['dashboard', 'layouts.app'], function (ViewFactory $view) {
            if (auth()->check()) {
                $view->with('currentMerchant', app(CurrentMerchant::class)->get());
            }
        });
    }
}
