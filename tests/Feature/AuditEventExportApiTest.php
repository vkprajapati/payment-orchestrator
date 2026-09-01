<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\Merchant;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Rate-limit buckets are cached per process — start each test fresh.
    Cache::flush();
});

/**
 * Create a merchant with a real API key, returning the raw key.
 *
 * (auditExport-prefixed helpers avoid clashing with sibling test files
 * under the same Pest process.)
 *
 * @return array{0: Merchant, 1: string}
 */
function auditExportMerchant(string $name = 'Audit Export Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $created = app(CreateApiKey::class)->create($merchant, 'CI/CD');

    return [$merchant, $created->rawKey];
}

function auditExportAuth(string $rawKey): array
{
    return ['Authorization' => "Bearer {$rawKey}"];
}

/**
 * Record an audit event through the production AuditLogger service and
 * return the created model.
 */
function auditExportLog(
    Merchant $merchant,
    AuditEventName $event = AuditEventName::PaymentCreated,
    AuditOutcome $outcome = AuditOutcome::Success,
    array $metadata = [],
): AuditEvent {
    app(AuditLogger::class)->log(
        $merchant,
        $event,
        'POST',
        '/api/v1/payments',
        outcome: $outcome,
        responseStatus: 201,
        paymentReference: 'pay_'.Str::ulid(),
        metadata: $metadata,
    );

    return $merchant->auditEvents()->latest('id')->first();
}

/**
 * Directly create an event with controlled column values (used for CSV
 * formatting/escaping and date-window tests).
 *
 * @param  array<string, mixed>  $attributes
 */
function auditExportEvent(Merchant $merchant, array $attributes = []): AuditEvent
{
    return $merchant->auditEvents()->create(array_merge([
        'reference' => 'evt_'.Str::ulid(),
        'event' => AuditEventName::PaymentCreated->value,
        'http_method' => 'POST',
        'path' => 'api/v1/payments',
        'response_status' => 201,
        'outcome' => AuditOutcome::Success->value,
        'payment_reference' => 'pay_'.Str::ulid(),
        'refund_reference' => null,
        'idempotency_replayed' => false,
        'metadata' => ['amount' => 1000],
        'performed_at' => now(),
    ], $attributes));
}

/**
 * Call the export endpoint. getJson sends Accept: application/json, so
 * validation failures render as proper 422s while CSV responses still
 * stream their raw text content.
 */
function auditExportExport(?string $rawKey = null, array $query = []): TestResponse
{
    $headers = $rawKey !== null ? auditExportAuth($rawKey) : [];

    return test()->getJson('/api/v1/audit-events/export?'.http_build_query($query), $headers);
}

/**
 * The approved CSV header row — mirrors AuditExporter::CSV_COLUMNS.
 *
 * @return list<string>
 */
function auditExportCsvColumns(): array
{
    return [
        'reference', 'event', 'outcome', 'http_method', 'path',
        'response_status', 'payment_reference', 'refund_reference',
        'idempotency_replayed', 'performed_at', 'created_at',
    ];
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

it('requires an API key to export audit events', function () {
    auditExportExport(null)->assertUnauthorized()->assertJson(['message' => 'Invalid API key.']);
});

it('rejects an invalid API key with the generic error', function () {
    auditExportExport(CreateApiKey::KEY_PREFIX.Str::random(CreateApiKey::SECRET_LENGTH))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);
});

// ---------------------------------------------------------------------------
// JSON export
// ---------------------------------------------------------------------------

it('exports JSON by default with the same representation as the retrieval API', function () {
    [$merchant, $rawKey] = auditExportMerchant();
    $event = auditExportLog($merchant, metadata: ['amount' => 1250, 'currency' => 'USD']);

    $response = auditExportExport($rawKey)->assertOk();

    $data = $response->json('data');

    expect(count($data))->toBe(1)
        ->and($data[0]['reference'])->toBe($event->reference)
        ->and($data[0]['event'])->toBe(AuditEventName::PaymentCreated->value)
        ->and($data[0]['outcome'])->toBe(AuditOutcome::Success->value)
        ->and($data[0]['payment_reference'])->toStartWith('pay_')
        ->and($data[0]['metadata'])->toBe(['amount' => 1250, 'currency' => 'USD']);

    // Identical field set to the paginated list resource.
    $listData = test()->getJson('/api/v1/audit-events', auditExportAuth($rawKey))->json('data.0');

    expect(array_keys($data[0]))->toBe(array_keys($listData));
});

it('supports an explicit format=json', function () {
    [$merchant, $rawKey] = auditExportMerchant();
    auditExportLog($merchant);

    auditExportExport($rawKey, ['format' => 'json'])->assertOk()->assertJsonCount(1, 'data');
});

it('exports only the authenticated merchant events in JSON', function () {
    [$merchantA, $keyA] = auditExportMerchant('Merchant A');
    [$merchantB] = auditExportMerchant('Merchant B');

    $eventA = auditExportLog($merchantA);
    $eventB = auditExportLog($merchantB);

    $references = collect(auditExportExport($keyA)->json('data'))->pluck('reference')->all();

    expect($references)->toBe([$eventA->reference])
        ->and($references)->not->toContain($eventB->reference);
});

it('orders JSON exports newest first with deterministic secondary ordering', function () {
    [$merchant, $rawKey] = auditExportMerchant();

    $older = auditExportLog($merchant);
    $first = auditExportLog($merchant);
    $second = auditExportLog($merchant);

    $sameMoment = now();

    $older->update(['created_at' => $sameMoment->copy()->subHour()]);
    $first->update(['created_at' => $sameMoment]);
    $second->update(['created_at' => $sameMoment]);

    auditExportExport($rawKey)->assertOk()->assertJsonPath('data.*.reference', [
        $second->reference, $first->reference, $older->reference,
    ]);
});

it('applies the read-boundary metadata whitelist to JSON exports', function () {
    [$merchant, $rawKey] = auditExportMerchant();

    // Poison the metadata column directly to prove the export does not
    // rely on the write-time AuditLogger whitelist alone.
    $merchant->auditEvents()->create([
        'reference' => 'evt_'.Str::ulid(),
        'event' => AuditEventName::PaymentCreated->value,
        'http_method' => 'POST',
        'path' => 'api/v1/payments',
        'response_status' => 201,
        'outcome' => AuditOutcome::Success->value,
        'payment_reference' => 'pay_'.Str::ulid(),
        'idempotency_replayed' => false,
        'metadata' => [
            'amount' => 900,
            'currency' => 'EUR',
            'api_key' => 'sk_live_export_secret',
            'request_body' => 'password=hunter2',
            'authorization' => 'Bearer stolen-token',
        ],
        'performed_at' => now(),
    ]);

    $data = auditExportExport($rawKey)->json('data.0');

    expect($data['metadata'])->toBe(['amount' => 900, 'currency' => 'EUR']);

    $serialized = json_encode($data);

    expect($serialized)->not->toContain('sk_live_export_secret')
        ->and($serialized)->not->toContain('hunter2')
        ->and($serialized)->not->toContain('stolen-token');
});

// ---------------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------------

it('exports a downloadable CSV with the approved header row and safe columns', function () {
    [$merchant, $rawKey] = auditExportMerchant();

    $event = auditExportEvent($merchant, ['performed_at' => $ts = now()->setMilliseconds(0)]);
    $event->update(['created_at' => $ts->copy()]);
    $event->refresh();

    $response = auditExportExport($rawKey, ['format' => 'csv'])->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment');

    $content = $response->streamedContent();

    // UTF-8 BOM first, then the exact approved header row.
    $expectedHeader = implode(',', auditExportCsvColumns());

    expect($content)->toStartWith("\xEF\xBB\xBF".$expectedHeader."\n");

    // Exact deterministic row — null refund_reference renders as an empty
    // field between two commas, booleans as true/false, dates as ISO-8601.
    $expectedRow = implode(',', [
        $event->reference,
        'payment.created',
        'success',
        'POST',
        'api/v1/payments',
        '201',
        $event->payment_reference,
        '',
        'false',
        $event->performed_at->toISOString(),
        $event->created_at->toISOString(),
    ]);

    expect($content)->toBe("\xEF\xBB\xBF".$expectedHeader."\n".$expectedRow."\n");
});

it('excludes metadata and internal identifiers from CSV exports', function () {
    [$merchant, $rawKey] = auditExportMerchant();

    $event = auditExportEvent($merchant, [
        'metadata' => ['amount' => 500, 'api_key' => 'sk_live_csv_secret'],
    ]);

    $content = auditExportExport($rawKey, ['format' => 'csv'])->streamedContent();

    $lines = explode("\n", $content);
    $header = $lines[0]; // first line carries the BOM + header
    $row = $lines[1];

    $rowFields = str_getcsv($row);

    expect($header)->not->toContain('metadata')
        ->and($row)->not->toContain('sk_live_csv_secret')
        // Field-level check: no CSV field equals an internal numeric ID.
        ->and(count($rowFields))->toBe(11)
        ->and($rowFields)->not->toContain((string) $event->getKey())
        ->and($rowFields)->not->toContain((string) $merchant->getKey())
        ->and(str_starts_with($row, $event->reference.','))->toBeTrue();
});

it('escapes commas, quotes and newlines in CSV values and preserves UTF-8', function () {
    [$merchant, $rawKey] = auditExportMerchant();

    $trickyPath = "api/v1/payments?note=\"a,b\"\nsecond line – žšť 日本語";
    auditExportEvent($merchant, ['path' => $trickyPath]);

    $content = auditExportExport($rawKey, ['format' => 'csv'])->streamedContent();

    // The tricky path must appear as one quoted field with doubled quotes;
    // the embedded newline stays inside the quotes; UTF-8 is untouched.
    $expectedField = '"'.str_replace('"', '""', $trickyPath).'"';

    expect($content)->toContain($expectedField)
        ->and($content)->toContain('日本語')
        ->and(mb_check_encoding($content, 'UTF-8'))->toBeTrue();
});

it('exports only the authenticated merchant events in CSV', function () {
    [$merchantA, $keyA] = auditExportMerchant('Merchant A');
    [$merchantB] = auditExportMerchant('Merchant B');

    $eventA = auditExportLog($merchantA);
    $eventB = auditExportLog($merchantB);

    $content = auditExportExport($keyA, ['format' => 'csv'])->streamedContent();

    expect($content)->toContain($eventA->reference)
        ->and($content)->not->toContain($eventB->reference);
});

// ---------------------------------------------------------------------------
// Filtering
// ---------------------------------------------------------------------------

it('filters JSON exports by event', function () {
    [$merchant, $rawKey] = auditExportMerchant();

    auditExportLog($merchant);
    auditExportLog($merchant);
    auditExportLog($merchant, AuditEventName::RefundCreated);

    auditExportExport($rawKey, ['event' => AuditEventName::PaymentCreated->value])
        ->assertOk()
        ->assertJsonCount(2, 'data');

    auditExportExport($rawKey, ['event' => AuditEventName::RefundCreated->value])
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters JSON exports by outcome', function () {
    [$merchant, $rawKey] = auditExportMerchant();

    auditExportLog($merchant);
    auditExportLog($merchant, AuditEventName::PaymentProcessingRequested, AuditOutcome::Failure);

    auditExportExport($rawKey, ['outcome' => AuditOutcome::Failure->value])
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('combines filters and date boundaries in JSON exports', function () {
    [$merchant, $rawKey] = auditExportMerchant();

    auditExportEvent($merchant, [
        'performed_at' => now()->subDay(),
        'outcome' => AuditOutcome::Failure->value,
    ]);
    auditExportEvent($merchant, [
        'outcome' => AuditOutcome::Failure->value,
    ]);
    auditExportEvent($merchant);

    // Whole-day semantics: bare Y-m-d "from" starts at 00:00:00 today,
    // so only today's failing event matches.
    auditExportExport($rawKey, [
        'outcome' => AuditOutcome::Failure->value,
        'from' => now()->toDateString(),
    ])->assertOk()->assertJsonCount(1, 'data');

    // Whole-day semantics: bare Y-m-d "to" includes the entire end day,
    // so all three events (yesterday's included) fall inside it.
    auditExportExport($rawKey, [
        'to' => now()->toDateString(),
    ])->assertOk()->assertJsonCount(3, 'data');

    // A window covering only yesterday matches exactly the old event.
    auditExportExport($rawKey, [
        'from' => now()->subDay()->toDateString(),
        'to' => now()->subDay()->toDateString(),
    ])->assertOk()->assertJsonCount(1, 'data');
});

it('filters CSV exports by event', function () {
    [$merchant, $rawKey] = auditExportMerchant();

    $paymentEvent = auditExportLog($merchant);
    $refundEvent = auditExportLog($merchant, AuditEventName::RefundCreated);

    $content = auditExportExport($rawKey, [
        'format' => 'csv',
        'event' => AuditEventName::RefundCreated->value,
    ])->streamedContent();

    expect($content)->toContain($refundEvent->reference)
        ->and($content)->not->toContain($paymentEvent->reference);
});

it('rejects invalid export filters and formats', function (array $query, string $field) {
    [, $rawKey] = auditExportMerchant();

    auditExportExport($rawKey, $query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'invalid event' => [['event' => 'payment.unknown'], 'event'],
    'invalid outcome' => [['outcome' => 'mystery'], 'outcome'],
    'invalid format' => [['format' => 'xml'], 'format'],
    'non-string format' => [['format' => 17], 'format'],
    'invalid from date' => [['from' => 'not-a-date'], 'from'],
    'invalid to date' => [['to' => 'not-a-date'], 'to'],
]);

// ---------------------------------------------------------------------------
// Merchant isolation
// ---------------------------------------------------------------------------

it('cannot export another merchant events in JSON or CSV', function () {
    [$merchantA, $keyA] = auditExportMerchant('Merchant A');
    [$merchantB] = auditExportMerchant('Merchant B');

    auditExportLog($merchantB);
    auditExportLog($merchantB, AuditEventName::RefundCreated, AuditOutcome::Failure);

    auditExportExport($keyA)->assertOk()->assertJsonCount(0, 'data');

    expect(auditExportExport($keyA, ['format' => 'csv'])->streamedContent())
        ->not->toContain('refund.created');
});

it('ignores merchant_id supplied via query parameters', function () {
    [$merchantA, $keyA] = auditExportMerchant('Merchant A');
    [$merchantB] = auditExportMerchant('Merchant B');

    $eventA = auditExportLog($merchantA);
    auditExportLog($merchantB);

    // Attempting to widen scope or target another merchant has no effect.
    $references = collect(auditExportExport($keyA, [
        'merchant_id' => $merchantB->getKey(),
    ])->json('data'))->pluck('reference')->all();

    expect($references)->toBe([$eventA->reference])
        ->and(auditExportExport($keyA, [
            'merchant_id' => $merchantB->getKey(),
            'format' => 'csv',
        ])->streamedContent())->toContain($eventA->reference);
});

it('filters never bypass merchant scope on export', function () {
    [$merchantA, $keyA] = auditExportMerchant('Merchant A');
    [$merchantB] = auditExportMerchant('Merchant B');

    auditExportLog($merchantA, AuditEventName::PaymentCreated);
    auditExportLog($merchantB, AuditEventName::RefundCreated, AuditOutcome::Failure);

    // Merchant A has no refund.created; filtering cannot surface Merchant B's.
    auditExportExport($keyA, [
        'event' => AuditEventName::RefundCreated->value,
        'outcome' => AuditOutcome::Failure->value,
    ])->assertOk()->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------------------
// Size protection
// ---------------------------------------------------------------------------

it('exports successfully when within the configured limit', function () {
    config(['audit.export.max_events' => 5]);
    [$merchant, $rawKey] = auditExportMerchant();

    collect(range(1, 5))->each(fn () => auditExportLog($merchant));

    auditExportExport($rawKey)->assertOk()->assertJsonCount(5, 'data');
});

it('rejects exports exceeding the configured limit without truncating', function () {
    config(['audit.export.max_events' => 2]);
    [$merchant, $rawKey] = auditExportMerchant();

    collect(range(1, 5))->each(fn () => auditExportLog($merchant));

    $response = auditExportExport($rawKey)->assertUnprocessable();
    $message = $response->json('message');

    // Controlled client error: no partial data, no internal row counts.
    expect($message)->toContain('Narrow the export range')
        ->and($message)->toContain('2')
        ->and($response->json())->not->toHaveKey('data')
        ->and(AuditEvent::count())->toBe(5);

    // The same applies to CSV — never a truncated document.
    auditExportExport($rawKey, ['format' => 'csv'])->assertUnprocessable();
});

it('applies filters before the size cap so narrower ranges still export', function () {
    config(['audit.export.max_events' => 2]);
    [$merchant, $rawKey] = auditExportMerchant();

    collect(range(1, 5))->each(fn () => auditExportLog($merchant));
    auditExportLog($merchant, AuditEventName::RefundCreated);

    // 6 events exceed the cap, but the narrowed filter matches 1.
    auditExportExport($rawKey, ['event' => AuditEventName::RefundCreated->value])
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

it('routes the export endpoint through the dedicated export bucket', function () {
    $export = Route::getRoutes()->getByName('api.v1.audit-events.export');
    $index = Route::getRoutes()->getByName('api.v1.audit-events.index');
    $show = Route::getRoutes()->getByName('api.v1.audit-events.show');

    expect($export->gatherMiddleware())->toContain('api.key', 'throttle:export')
        ->and($index->gatherMiddleware())->toContain('throttle:standard')
        ->and($show->gatherMiddleware())->toContain('throttle:standard');

    // "export" must be registered before the {reference} route so it is
    // never matched as a reference.
    $order = collect(Route::getRoutes()->getRoutes())->map->getName();

    expect($order->search('api.v1.audit-events.export'))
        ->toBeLessThan($order->search('api.v1.audit-events.show'));
});

it('rate limits exports independently of the standard bucket', function () {
    config(['rate_limiting.buckets.export.max_attempts' => 2]);
    [$merchant, $rawKey] = auditExportMerchant();
    auditExportLog($merchant);

    auditExportExport($rawKey)->assertOk();
    auditExportExport($rawKey)->assertOk();
    auditExportExport($rawKey)->assertStatus(429);

    // The standard list bucket is a separate budget and still works.
    test()->getJson('/api/v1/audit-events', auditExportAuth($rawKey))->assertOk();
});

// ---------------------------------------------------------------------------
// Audit recursion prevention
// ---------------------------------------------------------------------------

it('creates zero new audit events when listing, retrieving, or exporting', function () {
    [$merchant, $rawKey] = auditExportMerchant();
    auditExportLog($merchant);

    $before = AuditEvent::count();

    $event = $merchant->auditEvents()->latest('id')->first();

    test()->getJson('/api/v1/audit-events', auditExportAuth($rawKey))->assertOk();
    test()->getJson("/api/v1/audit-events/{$event->reference}", auditExportAuth($rawKey))->assertOk();
    auditExportExport($rawKey)->assertOk();
    auditExportExport($rawKey, ['format' => 'csv'])->assertOk();

    expect(AuditEvent::count())->toBe($before);
});

// ---------------------------------------------------------------------------
// Regression — existing audit behavior remains correct
// ---------------------------------------------------------------------------

it('regression: payment creation still logs exactly once and replays do not duplicate', function () {
    [$merchant, $rawKey] = auditExportMerchant();
    $headers = auditExportAuth($rawKey) + ['Idempotency-Key' => 'export-replay-1'];
    $payload = ['amount' => 1000, 'currency' => 'USD'];

    $this->postJson('/api/v1/payments', $payload, $headers)->assertCreated();
    $this->postJson('/api/v1/payments', $payload, $headers)->assertCreated();

    auditExportExport($rawKey)->assertOk()->assertJsonCount(1, 'data');
});

it('regression: refund and processing events remain visible in exports', function () {
    [$merchant, $rawKey] = auditExportMerchant();

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], auditExportAuth($rawKey))
        ->assertCreated();

    $reference = $this->postJson('/api/v1/payments', ['amount' => 2500, 'currency' => 'USD'], auditExportAuth($rawKey))
        ->assertCreated()
        ->json('data.reference');

    $this->postJson("/api/v1/payments/{$reference}/process", [], auditExportAuth($rawKey))->assertOk();

    $events = collect(auditExportExport($rawKey)->json('data'))->pluck('event')->all();

    expect($events)->toContain(AuditEventName::PaymentCreated->value)
        ->and($events)->toContain(AuditEventName::PaymentProcessingRequested->value);
});
