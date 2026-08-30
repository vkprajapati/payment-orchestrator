<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreatePayment
{
    /**
     * Create a payment for the given merchant.
     *
     * The merchant is always passed explicitly and resolved server-side
     * upstream (e.g. from ApiRequestContext in the future API layer) —
     * merchant identity is never accepted from request input.
     *
     * The payment reference is generated as "pay_" + a ULID: ULIDs are
     * URL-safe, lexicographically sortable (handy for debugging), not
     * derived from the database ID, and globally unique. The database's
     * UNIQUE constraint on payments.reference remains the ultimate
     * guarantee against collisions.
     *
     * @param  array{amount: int, currency: string, description?: string|null, idempotency_key?: string|null, metadata?: array<string, mixed>|null}  $data
     *
     * @throws InvalidArgumentException when the amount is not a positive
     *                                  integer or the currency is not a 3-letter code
     */
    public function create(Merchant $merchant, array $data): Payment
    {
        $amount = $data['amount'] ?? null;

        if (! is_int($amount) || $amount <= 0) {
            throw new InvalidArgumentException('The payment amount must be a positive integer in the smallest currency unit (e.g. 1050 = $10.50).');
        }

        $currency = Str::upper(trim($data['currency'] ?? ''));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('The payment currency must be a 3-letter ISO 4217 code.');
        }

        return $merchant->payments()->create([
            'reference' => self::generateReference(),
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentStatus::Pending,
            'description' => $data['description'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /**
     * Generate a unique, URL-safe, publicly exposable payment reference.
     */
    public static function generateReference(): string
    {
        return 'pay_'.Str::ulid();
    }
}
