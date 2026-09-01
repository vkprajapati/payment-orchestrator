<?php

namespace App\Providers;

use App\Models\ApiKey;
use App\Models\Merchant;
use App\Policies\ApiKeyPolicy;
use App\Policies\MerchantPolicy;
use App\Services\ApiKeys\ApiRequestContext;
use App\Services\Merchants\CurrentMerchant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->registerRateLimiters();
    }

    /**
     * Register merchant-aware rate-limit buckets.
     *
     * Each callback returns a Limit whose values come from config so
     * they can be tuned per-environment without code changes. The
     * limiter key itself is resolved in ThrottleApiRequests from the
     * authenticated merchant context — these callbacks only define
     * the attempt budget and decay window.
     *
     * Buckets:
     *   standard    — reads & ordinary API operations (generous)
     *   sensitive   — state-changing writes (stricter)
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('standard', function (Request $request, string $key): Limit {
            $attempts = (int) config('rate_limiting.buckets.standard.max_attempts', 1200);
            $decay = (int) config('rate_limiting.buckets.standard.decay_minutes', 1);

            return Limit::perMinutes($decay, $attempts);
        });

        RateLimiter::for('sensitive', function (Request $request, string $key): Limit {
            $attempts = (int) config('rate_limiting.buckets.sensitive.max_attempts', 60);
            $decay = (int) config('rate_limiting.buckets.sensitive.decay_minutes', 1);

            return Limit::perMinutes($decay, $attempts);
        });

        RateLimiter::for('export', function (Request $request, string $key): Limit {
            $attempts = (int) config('rate_limiting.buckets.export.max_attempts', 30);
            $decay = (int) config('rate_limiting.buckets.export.decay_minutes', 1);

            return Limit::perMinutes($decay, $attempts);
        });
    }
}
