<?php

namespace App\Providers;

use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\PaymentProviderResolver;
use App\Contracts\Payments\PaymentRoutingStrategy;
use App\Contracts\Payments\PaymentWebhookProvider;
use App\Services\Payments\DefaultPaymentProviderResolver;
use App\Services\Payments\DefaultPaymentRoutingStrategy;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\PaymentWebhookManager;
use App\Services\Payments\Providers\MockPaymentProvider;
use App\Services\Payments\Providers\PayUPaymentProvider;
use App\Services\Payments\Providers\Przelewy24PaymentProvider;
use App\Services\Payments\Providers\RazorpayPaymentProvider;
use App\Services\Payments\Providers\StripePaymentProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the payment provider architecture.
 *
 * Choice: a dedicated service provider rather than AppServiceProvider,
 * because provider registration is a cohesive, growing domain (more
 * providers and webhook handlers will be added in later steps) and
 * keeping it separate keeps the base provider lean.
 *
 * Both managers are bound as singletons: they are plain in-memory
 * registries with no request-scoped state, so one instance per process
 * is correct — and safe even under Octane/workers because a registry of
 * stateless provider objects carries no user data.
 */
class PaymentServiceProvider extends ServiceProvider
{
    /**
     * Provider classes registered with the manager, in stable order.
     *
     * @var list<class-string<PaymentProvider>>
     */
    private const PROVIDERS = [
        MockPaymentProvider::class,
        StripePaymentProvider::class,
        Przelewy24PaymentProvider::class,
        RazorpayPaymentProvider::class,
        PayUPaymentProvider::class,
    ];

    public function register(): void
    {
        $this->app->singleton(PaymentProviderManager::class, function (): PaymentProviderManager {
            $manager = new PaymentProviderManager;

            foreach (self::PROVIDERS as $providerClass) {
                $manager->register(new $providerClass);
            }

            return $manager;
        });

        $this->app->singleton(PaymentWebhookManager::class, function (): PaymentWebhookManager {
            $manager = new PaymentWebhookManager;

            // Interface segregation: only providers that implement the
            // webhook contract are registered for webhook handling.
            foreach (self::PROVIDERS as $providerClass) {
                $provider = new $providerClass;

                if ($provider instanceof PaymentWebhookProvider) {
                    $manager->register($provider);
                }
            }

            return $manager;
        });

        // Provider routing: the default resolver picks an explicit provider
        // when requested and falls back to the mock provider. TEMPORARY
        // strategy — merchant configuration and routing rules will replace
        // the fallback in a later step (see the resolver's docblock).
        // Singleton is safe: stateless, holds no request data.
        $this->app->singleton(PaymentProviderResolver::class, DefaultPaymentProviderResolver::class);

        // Provider routing strategy for failover: returns the ordered list of
        // eligible providers. Default returns only 'mock' because the
        // placeholder providers (Stripe/P24/Razorpay/PayU) report
        // supports(charge) === false at runtime, so they are excluded.
        // Singleton is safe: stateless, holds no request data.
        $this->app->singleton(PaymentRoutingStrategy::class, DefaultPaymentRoutingStrategy::class);
    }
}
