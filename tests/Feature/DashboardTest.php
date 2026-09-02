<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Models\AuditEvent;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Merchants\CurrentMerchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Attach a user to a merchant as owner and return both.
 *
 * @return array{0: Merchant, 1: User}
 */
function dashboardMerchant(string $name = 'Dashboard Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $user = User::factory()->create();
    $user->merchants()->attach($merchant, ['role' => 'owner']);

    return [$merchant, $user];
}

function dashboardVisit(Merchant $merchant, User $user)
{
    return test()->withSession([CurrentMerchant::SESSION_KEY => $merchant->id])
        ->actingAs($user)
        ->get('/dashboard');
}

it('redirects guests away from the dashboard', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('renders the dashboard for an authenticated merchant', function () {
    [$merchant, $user] = dashboardMerchant();

    dashboardVisit($merchant, $user)
        ->assertOk()
        ->assertSee($merchant->name)
        ->assertSee('Payment summary')
        ->assertSee('Refund summary')
        ->assertSee('Recent activity')
        ->assertSee('Audit pipeline health');
});

it('shows real payment metrics grouped by status', function () {
    [$merchant, $user] = dashboardMerchant();

    Payment::factory()->count(3)->succeeded()->for($merchant, 'merchant')->create();
    Payment::factory()->count(2)->failed()->for($merchant, 'merchant')->create();
    Payment::factory()->count(1)->create(['merchant_id' => $merchant->id]); // pending

    dashboardVisit($merchant, $user)
        ->assertOk()
        ->assertSeeInOrder(['Payments', '6'])
        ->assertSeeInOrder(['Succeeded', '3'])
        ->assertSeeInOrder(['Failed', '2'])
        ->assertSeeInOrder(['In flight', '1']);
});

it('shows real refund metrics grouped by status', function () {
    [$merchant, $user] = dashboardMerchant();

    $payment = Payment::factory()->succeeded()->for($merchant, 'merchant')->create();

    Refund::factory()->count(2)
        ->for($payment)
        ->for($merchant, 'merchant')
        ->create(['status' => 'succeeded']);
    Refund::factory()
        ->for($payment)
        ->for($merchant, 'merchant')
        ->create(['status' => 'failed']);

    dashboardVisit($merchant, $user)
        ->assertOk()
        ->assertSee('Refund summary')
        ->assertSeeInOrder(['Succeeded', '2'])
        ->assertSeeInOrder(['Failed', '1']);
});

it('renders the empty state for a merchant with no activity', function () {
    [$merchant, $user] = dashboardMerchant();

    dashboardVisit($merchant, $user)
        ->assertOk()
        ->assertSee('No activity yet')
        ->assertSee('Create an API key')
        ->assertSeeInOrder(['Payments', '0']);
});

it('displays the audit health status', function () {
    [$merchant, $user] = dashboardMerchant();

    dashboardVisit($merchant, $user)
        ->assertOk()
        ->assertSee('Audit pipeline health')
        ->assertSee('Healthy');
});

it('flags the audit health as attention required when stale events exist', function () {
    config(['audit.retention.days' => 1]);
    [$merchant, $user] = dashboardMerchant();

    $merchant->auditEvents()->create([
        'reference' => 'evt_'.Str::ulid(),
        'event' => 'payment.created',
        'http_method' => 'POST',
        'path' => 'api/v1/payments',
        'response_status' => 201,
        'outcome' => 'success',
        'payment_reference' => 'pay_'.Str::ulid(),
        'idempotency_replayed' => false,
        'performed_at' => now()->subDays(3),
    ]);

    dashboardVisit($merchant, $user)
        ->assertOk()
        ->assertSee('Attention required');
});

it('never shows another merchant payments or refunds', function () {
    [$merchantA, $userA] = dashboardMerchant('Merchant A');
    [$merchantB] = dashboardMerchant('Merchant B');

    // One payment for A, five for B: if isolation broke, A's total would
    // include B's payments (6 instead of 1).
    Payment::factory()->succeeded()->for($merchantA, 'merchant')->create();
    Payment::factory()->count(5)->succeeded()->for($merchantB, 'merchant')->create();

    dashboardVisit($merchantA, $userA)
        ->assertOk()
        ->assertSeeInOrder(['Payments', '1'])
        ->assertSeeInOrder(['Succeeded', '1']);
});

it('never exposes api key secrets hashes or internal identifiers', function () {
    [$merchant, $user] = dashboardMerchant();

    Payment::factory()->succeeded()->for($merchant, 'merchant')->create();

    $created = app(CreateApiKey::class)->create($merchant, 'Secret Key Test');

    $html = dashboardVisit($merchant, $user)->getContent();

    expect($html)->not->toContain($created->rawKey)
        ->and($html)->not->toContain('key_hash')
        ->and($html)->not->toContain('key_prefix')
        ->and($html)->not->toContain('sk_test_')
        ->and($html)->not->toContain('merchant_id');
});

it('bounds the recent activity feed to the configured limit', function () {
    [$merchant, $user] = dashboardMerchant();

    // Oldest first: each event gets a progressively newer performed_at and
    // its own payment reference (the field the feed renders).
    $paymentReferences = collect(range(1, 12))->map(function (int $i) use ($merchant): string {
        $paymentReference = 'pay_'.Str::ulid();

        $merchant->auditEvents()->create([
            'reference' => 'evt_'.Str::ulid(),
            'event' => 'payment.created',
            'http_method' => 'POST',
            'path' => 'api/v1/payments',
            'response_status' => 201,
            'outcome' => 'success',
            'payment_reference' => $paymentReference,
            'idempotency_replayed' => false,
            'performed_at' => now()->subMinutes(20 - $i),
        ]);

        return $paymentReference;
    });

    $html = dashboardVisit($merchant, $user)->getContent();

    // Newest 8 are shown; the 4 oldest are not.
    expect($paymentReferences->slice(4)->every(fn (string $r) => str_contains($html, $r)))->toBeTrue()
        ->and($paymentReferences->take(4)->every(fn (string $r) => ! str_contains($html, $r)))->toBeTrue();
});

it('creates zero audit events when the dashboard is viewed', function () {
    [$merchant, $user] = dashboardMerchant();

    $before = AuditEvent::count();

    dashboardVisit($merchant, $user)->assertOk();

    expect(AuditEvent::count())->toBe($before);
});

it('excludes archived audit events from the dashboard', function () {
    [$merchant, $user] = dashboardMerchant();

    $archived = $merchant->auditEvents()->create([
        'reference' => 'evt_'.Str::ulid(),
        'event' => 'payment.created',
        'http_method' => 'POST',
        'path' => 'api/v1/payments',
        'response_status' => 201,
        'outcome' => 'success',
        'payment_reference' => 'pay_'.Str::ulid(),
        'idempotency_replayed' => false,
        'performed_at' => now()->subMinutes(5),
    ]);
    $archived->delete(); // archived via SoftDeletes

    $html = dashboardVisit($merchant, $user)->assertOk()->getContent();

    expect($html)->not->toContain($archived->reference)
        ->and(AuditEvent::withTrashed()->count())->toBe(1);
});
