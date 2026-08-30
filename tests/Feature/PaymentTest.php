<?php

use App\Actions\Payments\CreatePayment;
use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates a payment with a merchant, amount, currency and pending default', function () {
    $merchant = Merchant::factory()->create();

    $payment = $merchant->payments()->create([
        'reference' => 'pay_'.Str::ulid(),
        'amount' => 1050,
        'currency' => 'USD',
    ]);

    expect($payment->exists)->toBeTrue()
        ->and($payment->merchant->is($merchant))->toBeTrue()
        ->and($payment->amount)->toBe(1050)
        ->and($payment->currency)->toBe('USD')
        // The DB default is not backfilled into the in-memory model,
        // so the status is asserted after refreshing from the database.
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('belongs to the correct merchant among many', function () {
    $merchantA = Merchant::factory()->create();
    $merchantB = Merchant::factory()->create();

    $payment = Payment::factory()->for($merchantA)->create();

    expect($payment->merchant->is($merchantA))->toBeTrue()
        ->and($payment->merchant->is($merchantB))->toBeFalse()
        ->and($merchantA->payments()->count())->toBe(1)
        ->and($merchantB->payments()->count())->toBe(0);
});

it('generates references starting with pay_ that are unique', function () {
    $references = collect(range(1, 25))
        ->map(fn () => CreatePayment::generateReference())
        ->all();

    expect($references)->each->toStartWith('pay_')
        ->and(count($references))->toBe(count(array_unique($references)))
        ->and(Payment::count())->toBe(0);
});

it('rejects duplicate references at the database level', function () {
    $payment = Payment::factory()->create();

    expect(fn () => Payment::query()->create([
        'reference' => $payment->reference,
        'amount' => 1000,
        'currency' => 'USD',
    ]))->toThrow(QueryException::class);
});

it('stores amounts as integers in the smallest currency unit', function () {
    $payment = Payment::factory()->create(['amount' => 1050]);

    expect($payment->amount)->toBeInt()
        ->and($payment->amount)->toBe(1050)
        ->and($payment->refresh()->amount)->toBe(1050);
});

it('rejects zero and negative amounts via the action and the database constraint', function () {
    it('defaults the status to pending when none is provided', function () {
        $payment = new Payment([
            'reference' => 'pay_'.Str::ulid(),
            'amount' => 1000,
            'currency' => 'USD',
        ]);
        $payment->merchant_id = Merchant::factory()->create()->id;
        $payment->save();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    });

    it('casts status to the PaymentStatus enum', function () {
        $payment = Payment::factory()->succeeded()->create();

        expect($payment->status)->toBeInstanceOf(PaymentStatus::class)
            ->and($payment->status->value)->toBe('succeeded');
    });

    it('round-trips metadata through JSONB', function () {
        $metadata = ['order_id' => 'ORD-123', 'customer_id' => 'CUS-456'];

        $payment = Payment::factory()->create(['metadata' => $metadata]);

        expect($payment->refresh()->metadata)->toBe($metadata);
    });

    it('enforces idempotency key uniqueness per merchant but allows it across merchants', function () {
        $merchantA = Merchant::factory()->create();
        $merchantB = Merchant::factory()->create();
        $action = new CreatePayment;

        $action->create($merchantA, ['amount' => 1000, 'currency' => 'USD', 'idempotency_key' => 'abc123']);

        // Same merchant + same key is rejected.
        expect(fn () => $action->create($merchantA, ['amount' => 2000, 'currency' => 'EUR', 'idempotency_key' => 'abc123']))
            ->toThrow(QueryException::class);

        // A different merchant may reuse the same key.
        $payment = $action->create($merchantB, ['amount' => 3000, 'currency' => 'PLN', 'idempotency_key' => 'abc123']);

        expect($payment->exists)->toBeTrue();
    });

    it('allows multiple payments without idempotency keys for the same merchant', function () {
        $merchant = Merchant::factory()->create();
        $action = new CreatePayment;

        $first = $action->create($merchant, ['amount' => 1000, 'currency' => 'USD']);
        $second = $action->create($merchant, ['amount' => 2000, 'currency' => 'USD']);

        expect($first->exists)->toBeTrue()
            ->and($second->exists)->toBeTrue()
            ->and($merchant->payments()->count())->toBe(2);
    });

    it('deletes payments when the merchant is deleted', function () {
        $merchant = Merchant::factory()->create();
        Payment::factory()->count(3)->for($merchant)->create();

        expect(Payment::count())->toBe(3);

        $merchant->delete();

        expect(Payment::count())->toBe(0);
    });

    it('creates payments through the CreatePayment action', function () {
        $merchant = Merchant::factory()->create();

        $payment = (new CreatePayment)->create($merchant, [
            'amount' => 1050,
            'currency' => 'usd',
            'description' => 'Example payment',
            'idempotency_key' => 'key-1',
            'metadata' => ['order_id' => 'ORD-123'],
        ]);

        expect($payment->exists)->toBeTrue()
            ->and($payment->reference)->toStartWith('pay_')
            ->and($payment->merchant->is($merchant))->toBeTrue()
            ->and($payment->status)->toBe(PaymentStatus::Pending)
            ->and($payment->currency)->toBe('USD')
            ->and($payment->description)->toBe('Example payment')
            ->and($payment->idempotency_key)->toBe('key-1')
            ->and($payment->metadata)->toBe(['order_id' => 'ORD-123'])
            ->and(Payment::query()->whereKey($payment->id)->exists())->toBeTrue();
    });

    it('normalizes lowercase currency codes and rejects invalid ones', function () {
        $merchant = Merchant::factory()->create();
        $action = new CreatePayment;

        expect($action->create($merchant, ['amount' => 1000, 'currency' => 'eur'])->currency)->toBe('EUR')
            ->and(fn () => $action->create($merchant, ['amount' => 1000, 'currency' => 'US']))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => $action->create($merchant, ['amount' => 1000, 'currency' => 'USDD']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('allows payments without a description', function () {
        $payment = (new CreatePayment)->create(
            Merchant::factory()->create(),
            ['amount' => 1000, 'currency' => 'USD'],
        );

        expect($payment->description)->toBeNull();
    });

    it('exposes status helper methods on the model', function () {
        $pending = Payment::factory()->create(['status' => PaymentStatus::Pending]);
        $processing = Payment::factory()->create(['status' => PaymentStatus::Processing]);
        $succeeded = Payment::factory()->create(['status' => PaymentStatus::Succeeded]);
        $failed = Payment::factory()->create(['status' => PaymentStatus::Failed]);

        expect($pending->isPending())->toBeTrue()->and($pending->isTerminal())->toBeFalse()
            ->and($processing->isProcessing())->toBeTrue()->and($processing->isTerminal())->toBeFalse()
            ->and($succeeded->isSucceeded())->toBeTrue()->and($succeeded->isTerminal())->toBeTrue()
            ->and($failed->isFailed())->toBeTrue()->and($failed->isTerminal())->toBeTrue();
    });

    it('reports terminal states for refund statuses in the enum', function () {
        expect(PaymentStatus::Refunded->isTerminal())->toBeTrue()
            ->and(PaymentStatus::PartiallyRefunded->isTerminal())->toBeTrue()
            ->and(PaymentStatus::Cancelled->isTerminal())->toBeTrue()
            ->and(PaymentStatus::Pending->isTerminal())->toBeFalse();
    });

    it('never accepts merchant identity through payment data', function () {
        $merchant = Merchant::factory()->create();
        $other = Merchant::factory()->create();

        // merchant_id is not fillable, so passing it in data has no effect.
        $payment = Payment::factory()->for($merchant)->make(['merchant_id' => $other->id]);

        expect($payment->merchant_id)->not->toBe($other->id)
            ->and($payment->merchant_id)->toBe($merchant->id);
    });

    $merchant = Merchant::factory()->create();
    $action = new CreatePayment;

    expect(fn () => $action->create($merchant, ['amount' => 0, 'currency' => 'USD']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $action->create($merchant, ['amount' => -100, 'currency' => 'USD']))
        ->toThrow(InvalidArgumentException::class);

    // Even bypassing the action, the CHECK constraint protects the table.
    expect(fn () => Payment::query()->create([
        'reference' => 'pay_'.Str::ulid(),
        'amount' => -100,
        'currency' => 'USD',
    ]))->toThrow(QueryException::class)
        ->and(Payment::count())->toBe(0);
});

it('stores ISO currency codes correctly', function () {
    $merchant = Merchant::factory()->create();

    foreach (['USD', 'EUR', 'PLN'] as $currency) {
        $payment = Payment::factory()->for($merchant)->create(['currency' => $currency]);

        expect($payment->refresh()->currency)->toBe($currency)
            ->and(strlen($payment->currency))->toBe(3);
    }
});
