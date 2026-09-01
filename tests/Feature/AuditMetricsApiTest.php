<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Actions\Audit\ArchiveAuditEvents;
use App\Actions\Audit\PruneAuditEvents;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

/**
 * (auditMetrics-prefixed helpers avoid clashing with sibling test files
 * under the same Pest process.)
 *
 * @return array{0: Merchant, 1: string}
 */
function auditMetricsMerchant(string $name = 'Metrics Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $created = app(CreateApiKey::class)->create($merchant, 'metrics');

    return [$merchant, $created->rawKey];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function auditMetricsEvent(Merchant $merchant, array $attributes = []): AuditEvent
{
    return $merchant->auditEvents()->create(array_merge([
        'reference' => 'evt_'.Str::ulid(),
        'event' => AuditEventName::PaymentCreated->value,
        'http_method' => 'POST',
        'path' => 'api/v1/payments',
        'response_status' => 201,
        'outcome' => AuditOutcome::Success->value,
        'payment_reference' => 'pay_'.Str::ulid(),
        'idempotency_replayed' => false,
        'metadata' => null,
        'performed_at' => now(),
    ], $attributes));
}

function auditMetricsGet(?string $rawKey = null, array $query = []): TestResponse
{
    $headers = $rawKey !== null ? ['Authorization' => "Bearer {$rawKey}"] : [];

    return test()->getJson('/api/v1/audit-events/metrics?'.http_build_query($query), $headers);
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

it('requires an API key for metrics', function () {
    auditMetricsGet(null)->assertUnauthorized()->assertJson(['message' => 'Invalid API key.']);
});

it('rejects an invalid API key with the generic metrics error', function () {
    auditMetricsGet('sk_test_'.Str::random(40))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);
});

// ---------------------------------------------------------------------------
// Basic metrics
// ---------------------------------------------------------------------------

it('returns zeroed metrics for a merchant with no audit events', function () {
    [, $rawKey] = auditMetricsMerchant();

    auditMetricsGet($rawKey)
        ->assertOk()
        ->assertJson([
            'data' => [
                'total' => 0,
                'by_event' => [],
                'by_outcome' => [],
                'time_range' => ['from' => null, 'to' => null],
            ],
        ]);
});

it('computes total, event grouping, and outcome grouping correctly', function () {
    [$merchant, $rawKey] = auditMetricsMerchant();

    auditMetricsEvent($merchant, ['performed_at' => now()->subDays(2)]);
    auditMetricsEvent($merchant);
    auditMetricsEvent($merchant, [
        'event' => AuditEventName::PaymentProcessingRequested->value,
        'outcome' => AuditOutcome::Failure->value,
        'response_status' => 422,
    ]);
    auditMetricsEvent($merchant, [
        'event' => AuditEventName::RefundCreated->value,
        'outcome' => AuditOutcome::Rejected->value,
        'response_status' => 404,
        'refund_reference' => 'ref_'.Str::ulid(),
        'payment_reference' => null,
    ]);

    $data = auditMetricsGet($rawKey)->assertOk()->json('data');

    expect($data['total'])->toBe(4)
        // toEqual (not toBe): SQL does not guarantee grouped-map ordering.
        ->and($data['by_event'])->toEqual([
            AuditEventName::PaymentCreated->value => 2,
            AuditEventName::PaymentProcessingRequested->value => 1,
            AuditEventName::RefundCreated->value => 1,
        ])
        ->and($data['by_outcome'])->toEqual([
            AuditOutcome::Success->value => 2,
            AuditOutcome::Failure->value => 1,
            AuditOutcome::Rejected->value => 1,
        ])
        ->and($data['time_range']['from'])->toBe(now()->subDays(2)->startOfSecond()->toISOString())
        ->and($data['time_range']['to'])->toBe(now()->startOfSecond()->toISOString());
});

it('does not fabricate categories for null outcomes', function () {
    [$merchant, $rawKey] = auditMetricsMerchant();

    auditMetricsEvent($merchant, ['outcome' => null]);

    $data = auditMetricsGet($rawKey)->assertOk()->json('data');

    // The null-outcome row counts towards total but belongs to no enum
    // category — no invented grouping key.
    expect($data['total'])->toBe(1)
        ->and($data['by_event'])->toBe([AuditEventName::PaymentCreated->value => 1])
        ->and($data['by_outcome'])->toBe([]);
});

// ---------------------------------------------------------------------------
// Filtering
// ---------------------------------------------------------------------------

it('filters metrics by event', function () {
    [$merchant, $rawKey] = auditMetricsMerchant();

    auditMetricsEvent($merchant);
    auditMetricsEvent($merchant);
    auditMetricsEvent($merchant, [
        'event' => AuditEventName::RefundCreated->value,
        'outcome' => AuditOutcome::Rejected->value,
        'refund_reference' => 'ref_'.Str::ulid(),
        'payment_reference' => null,
    ]);

    $data = auditMetricsGet($rawKey, [
        'event' => AuditEventName::PaymentCreated->value,
    ])->assertOk()->json('data');

    expect($data['total'])->toBe(2)
        ->and($data['by_event'])->toBe([AuditEventName::PaymentCreated->value => 2])
        ->and($data['by_outcome'])->toBe([AuditOutcome::Success->value => 2]);
});

it('filters metrics by outcome', function () {
    [$merchant, $rawKey] = auditMetricsMerchant();

    auditMetricsEvent($merchant);
    auditMetricsEvent($merchant, ['outcome' => AuditOutcome::Failure->value, 'response_status' => 422]);

    $data = auditMetricsGet($rawKey, ['outcome' => AuditOutcome::Failure->value])
        ->assertOk()
        ->json('data');

    expect($data['total'])->toBe(1)
        ->and($data['by_event'])->toBe([AuditEventName::PaymentCreated->value => 1])
        ->and($data['by_outcome'])->toBe([AuditOutcome::Failure->value => 1]);
});

it('combines event and outcome filters', function () {
    [$merchant, $rawKey] = auditMetricsMerchant();

    auditMetricsEvent($merchant);
    auditMetricsEvent($merchant, ['outcome' => AuditOutcome::Failure->value, 'response_status' => 422]);
    auditMetricsEvent($merchant, [
        'event' => AuditEventName::RefundCreated->value,
        'outcome' => AuditOutcome::Failure->value,
        'refund_reference' => 'ref_'.Str::ulid(),
        'payment_reference' => null,
    ]);

    $data = auditMetricsGet($rawKey, [
        'event' => AuditEventName::RefundCreated->value,
        'outcome' => AuditOutcome::Failure->value,
    ])->assertOk()->json('data');

    expect($data['total'])->toBe(1)
        ->and($data['by_event'])->toBe([AuditEventName::RefundCreated->value => 1]);
});

it('applies whole-day date semantics to metrics time filtering', function () {
    [$merchant, $rawKey] = auditMetricsMerchant();

    // Capture the instants at seeding time so later now() drift (even by
    // a second) cannot make the assertions flaky.
    $twoDaysAgo = now()->subDays(2)->startOfSecond();
    $yesterday = now()->subDay()->startOfSecond();
    $today = now()->startOfSecond();

    auditMetricsEvent($merchant, ['performed_at' => $twoDaysAgo]);
    auditMetricsEvent($merchant, ['performed_at' => $yesterday]);
    auditMetricsEvent($merchant, ['performed_at' => $today]);

    // Bare Y-m-d `from` starts at 00:00:00 today — only today's event.
    $fromToday = auditMetricsGet($rawKey, ['from' => $today->toDateString()])->assertOk()->json('data');
    expect($fromToday['total'])->toBe(1)
        ->and($fromToday['time_range']['from'])->toBe($today->toISOString());

    // A window covering only yesterday matches exactly that day's event.
    $yesterdayWindow = auditMetricsGet($rawKey, [
        'from' => $yesterday->toDateString(),
        'to' => $yesterday->toDateString(),
    ])->assertOk()->json('data');

    expect($yesterdayWindow['total'])->toBe(1)
        ->and($yesterdayWindow['time_range']['from'])->toBe($yesterday->toISOString())
        ->and($yesterdayWindow['time_range']['to'])->toBe($yesterday->toISOString());
});

it('rejects invalid metrics filters', function (array $query, string $field) {
    [, $rawKey] = auditMetricsMerchant();

    auditMetricsGet($rawKey, $query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'invalid event' => [['event' => 'payment.unknown'], 'event'],
    'invalid outcome' => [['outcome' => 'mystery'], 'outcome'],
    'invalid from date' => [['from' => 'not-a-date'], 'from'],
    'invalid to date' => [['to' => 'not-a-date'], 'to'],
    'invalid from format' => [['from' => '32/01/2026'], 'from'],
]);

// ---------------------------------------------------------------------------
// Merchant isolation
// ---------------------------------------------------------------------------

it('excludes other merchants events from metrics', function () {
    [$merchantA, $keyA] = auditMetricsMerchant('Merchant A');
    [$merchantB] = auditMetricsMerchant('Merchant B');

    // Merchant A: 1 event. Merchant B: 3 events of every kind.
    auditMetricsEvent($merchantA);
    auditMetricsEvent($merchantB);
    auditMetricsEvent($merchantB, [
        'event' => AuditEventName::PaymentProcessingRequested->value,
        'outcome' => AuditOutcome::Failure->value,
    ]);
    auditMetricsEvent($merchantB, [
        'event' => AuditEventName::RefundCreated->value,
        'outcome' => AuditOutcome::Rejected->value,
        'refund_reference' => 'ref_'.Str::ulid(),
        'payment_reference' => null,
    ]);

    $data = auditMetricsGet($keyA)->assertOk()->json('data');

    expect($data['total'])->toBe(1)
        ->and($data['by_event'])->toBe([AuditEventName::PaymentCreated->value => 1])
        ->and($data['by_outcome'])->toBe([AuditOutcome::Success->value => 1]);
});

it('ignores a merchant_id query parameter', function () {
    [$merchantA, $keyA] = auditMetricsMerchant('Merchant A');
    [$merchantB] = auditMetricsMerchant('Merchant B');

    auditMetricsEvent($merchantA);
    auditMetricsEvent($merchantB);
    auditMetricsEvent($merchantB);

    $data = auditMetricsGet($keyA, ['merchant_id' => $merchantB->getKey()])
        ->assertOk()
        ->json('data');

    // Attempting to widen scope to Merchant B has no effect.
    expect($data['total'])->toBe(1)
        ->and($data['by_event'])->toBe([AuditEventName::PaymentCreated->value => 1]);
});

it('returns independent totals per merchant', function () {
    [$merchantA, $keyA] = auditMetricsMerchant('Merchant A');
    [$merchantB, $keyB] = auditMetricsMerchant('Merchant B');

    auditMetricsEvent($merchantA);
    auditMetricsEvent($merchantA);
    auditMetricsEvent($merchantB);

    expect(auditMetricsGet($keyA)->json('data.total'))->toBe(2)
        ->and(auditMetricsGet($keyB)->json('data.total'))->toBe(1);
});

// ---------------------------------------------------------------------------
// Response security
// ---------------------------------------------------------------------------

it('exposes aggregate fields only and never internal data', function () {
    [$merchant, $rawKey] = auditMetricsMerchant();

    // Poison the metadata column directly — metrics must never surface it.
    auditMetricsEvent($merchant, [
        'metadata' => [
            'amount' => 42,
            'api_key' => 'sk_test_metrics_secret',
            'authorization' => 'Bearer stolen-token',
        ],
    ]);

    $response = auditMetricsGet($rawKey)->assertOk();
    $data = $response->json('data');

    expect(array_keys($data))->toBe(['total', 'by_event', 'by_outcome', 'time_range'])
        ->and(array_keys($data['time_range']))->toBe(['from', 'to']);

    // Recursively collect every key anywhere in the response document and
    // require each one to belong to the approved aggregate whitelist —
    // no id, merchant_id, metadata, or any event-level key can appear.
    $keys = [];
    $collectKeys = function (array $payload) use (&$collectKeys, &$keys): void {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($value)) {
                $collectKeys($value);
            }
        }
    };
    $collectKeys($data);

    // Structural keys plus the domain-sanctioned grouping keys (the enum
    // values of AuditEventName / AuditOutcome) — anything else anywhere in
    // the document is a violation.
    $allowed = array_merge(
        ['total', 'by_event', 'by_outcome', 'time_range', 'from', 'to'],
        array_column(AuditEventName::cases(), 'value'),
        array_column(AuditOutcome::cases(), 'value'),
    );

    expect(array_values(array_diff($keys, $allowed)))->toBe([]);

    $serialized = $response->getContent() ?? '';

    expect($serialized)->not->toContain('sk_test_metrics_secret')
        ->and($serialized)->not->toContain('stolen-token')
        ->and($serialized)->not->toContain('amount')
        ->and($serialized)->not->toContain('evt_')
        ->and($serialized)->not->toContain('pay_')
        ->and($serialized)->not->toContain('merchant_id')
        ->and($serialized)->not->toContain('metadata')
        ->and($serialized)->not->toContain('payment_id')
        ->and($serialized)->not->toContain('refund_id');
});

// ---------------------------------------------------------------------------
// Audit recursion prevention
// ---------------------------------------------------------------------------

it('creates zero new audit events across repeated metrics requests', function () {
    [$merchant, $rawKey] = auditMetricsMerchant();
    auditMetricsEvent($merchant);

    $before = AuditEvent::count();

    auditMetricsGet($rawKey)->assertOk();
    auditMetricsGet($rawKey, ['event' => AuditEventName::PaymentCreated->value])->assertOk();
    auditMetricsGet($rawKey, ['from' => now()->toDateString()])->assertOk();
    auditMetricsGet($rawKey)->assertOk();

    expect(AuditEvent::count())->toBe($before);
});

// ---------------------------------------------------------------------------
// Query behavior
// ---------------------------------------------------------------------------

it('aggregates in the database without loading event rows', function () {
    [$merchant, $rawKey] = auditMetricsMerchant();
    collect(range(1, 10))->each(fn () => auditMetricsEvent($merchant));

    $rowLoads = [];

    DB::listen(function ($query) use (&$rowLoads): void {
        if (str_contains($query->sql, 'from "audit_events"')
            && str_starts_with(trim($query->sql), 'select')) {
            $rowLoads[] = $query->sql;
        }
    });

    auditMetricsGet($rawKey)->assertOk();

    // Every audit_events select must be an aggregate (count / group by /
    // min-max) — never a plain row load of the table.
    expect($rowLoads)->not->toBeEmpty();

    foreach ($rowLoads as $sql) {
        expect(
            str_contains($sql, 'count(*)')
            || str_contains(strtolower($sql), 'group by')
            || str_contains(strtolower($sql), 'min(')
        )->toBeTrue("Non-aggregate audit_events select executed: {$sql}");
    }

    // Exactly the four expected aggregate queries: total, by_event,
    // by_outcome, time range.
    expect(count($rowLoads))->toBe(4);
});

it('routes metrics through the standard bucket before the reference route', function () {
    $metrics = Route::getRoutes()->getByName('api.v1.audit-events.metrics');
    $show = Route::getRoutes()->getByName('api.v1.audit-events.show');

    expect($metrics->gatherMiddleware())->toContain('api.key', 'throttle:standard')
        ->and($metrics->gatherMiddleware())->not->toContain('throttle:export', 'throttle:sensitive');

    // "metrics" must be registered before the {reference} route so it is
    // never matched as a reference.
    $order = collect(Route::getRoutes()->getRoutes())->map->getName();

    expect($order->search('api.v1.audit-events.metrics'))
        ->toBeLessThan($order->search('api.v1.audit-events.show'));
});

// ---------------------------------------------------------------------------
// Regression
// ---------------------------------------------------------------------------

it('regression: list, show, and export endpoints still work alongside metrics', function () {
    [$merchant, $rawKey] = auditMetricsMerchant();
    $event = auditMetricsEvent($merchant);
    $headers = ['Authorization' => "Bearer {$rawKey}"];

    $list = $this->getJson('/api/v1/audit-events', $headers)->assertOk();
    expect($list->json('meta.total'))->toBe(1);

    $export = $this->getJson('/api/v1/audit-events/export', $headers)->assertOk();
    expect(count($export->json('data')))->toBe(1);

    $show = $this->getJson("/api/v1/audit-events/{$event->reference}", $headers)->assertOk();
    expect($show->json('data.reference'))->toBe($event->reference);
});

it('regression: pruning still works and interacts correctly with metrics', function () {
    [$merchant, $rawKey] = auditMetricsMerchant();

    auditMetricsEvent($merchant, ['performed_at' => now()->subDays(31)]);
    auditMetricsEvent($merchant);

    expect(auditMetricsGet($rawKey)->json('data.total'))->toBe(2);

    // Two-stage lifecycle: the stale event must be archived first — archive
    // alone already excludes it from ordinary (active-row) metrics queries.
    app(ArchiveAuditEvents::class)->execute(retentionDays: 30);

    expect(auditMetricsGet($rawKey)->json('data.total'))->toBe(1);

    // Prune only affects archived rows beyond the grace window; the freshly
    // archived row survives, and metrics remain unchanged.
    app(PruneAuditEvents::class)->execute(retentionDays: 30);

    $data = auditMetricsGet($rawKey)->assertOk()->json('data');

    expect($data['total'])->toBe(1)
        ->and($data['by_event'])->toBe([AuditEventName::PaymentCreated->value => 1])
        ->and($data['time_range']['from'])->toBe(now()->startOfSecond()->toISOString())
        ->and(AuditEvent::withTrashed()->count())->toBe(2); // nothing hard-deleted yet
});

it('regression: payment flow audit logging still feeds metrics', function () {
    [$merchant, $rawKey] = auditMetricsMerchant();
    $headers = ['Authorization' => "Bearer {$rawKey}", 'Idempotency-Key' => 'metrics-regression-1'];
    $payload = ['amount' => 1000, 'currency' => 'USD'];

    $this->postJson('/api/v1/payments', $payload, $headers)->assertCreated();
    // Idempotent replay: still exactly one audit event.
    $this->postJson('/api/v1/payments', $payload, $headers)->assertCreated();

    $data = auditMetricsGet($rawKey)->assertOk()->json('data');

    expect($data['total'])->toBe(1)
        ->and($data['by_event'])->toBe([AuditEventName::PaymentCreated->value => 1])
        ->and($data['by_outcome'])->toBe([AuditOutcome::Success->value => 1]);
});
