<?php

use App\Exceptions\InvalidApiKeyException;
use App\Exceptions\PaymentProviderException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureCurrentMerchant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'merchant' => EnsureCurrentMerchant::class,
            'api.key' => AuthenticateApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(InvalidApiKeyException::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (PaymentProviderException $exception, Request $request) {
            if ($request->is('api/*')) {
                // Controlled provider failure (not configured / unsupported
                // operation) on an API route: a client-fixable validation
                // error. The message is static text from the exception and
                // never includes secrets or raw provider responses.
                return response()->json([
                    'message' => $exception->getMessage(),
                    'error' => 'provider_not_available',
                ], 422);
            }

            return null;
        });
    })->create();
