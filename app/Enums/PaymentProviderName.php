<?php

namespace App\Enums;

/**
 * Stable internal identifiers for payment providers.
 *
 * Values are machine identifiers, NOT display names — they are safe to
 * persist, log, and route on. Future provider routing should match on
 * these values so renames of provider classes never break stored data.
 */
enum PaymentProviderName: string
{
    case Stripe = 'stripe';
    case Przelewy24 = 'p24';
    case Razorpay = 'razorpay';
    case PayU = 'payu';
    case Mock = 'mock';

    /**
     * Normalize an arbitrary provider name for lookups: trimmed and
     * lowercased so resolution is case-insensitive.
     */
    public static function normalize(string $name): string
    {
        return strtolower(trim($name));
    }

    /**
     * Whether the given name refers to a known provider.
     */
    public static function isValid(string $name): bool
    {
        return self::tryFrom(self::normalize($name)) !== null;
    }
}
