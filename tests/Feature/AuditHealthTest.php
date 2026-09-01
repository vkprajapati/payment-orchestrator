<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Actions\Audit\ArchiveAuditEvents;
use App\Actions\Audit\GetAuditHealth;
use App\Actions\Audit\PruneAuditEvents;
use App\Data\Audit\AuditHealthResult;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\Merchant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
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
 * (auditHealth-prefixed helpers avoid clashing with sibling test files
 * under the same Pest process.)
 */
function auditHealthMerchant(string $name = 'Health Merchant'): Merchant
{
    return Merchant::factory()->create(['name' => $name]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function auditHealthEvent(Merchant $merchant, array $attributes = []): AuditEvent
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

function auditHealthCheck(): AuditHealthResult
{
    return app(GetAuditHealth::class)->execute();
}

function auditHealthHttp(?string $rawKey = null): TestResponse
{
    $headers = $rawKey !== null ? ['Authorization' => "Bearer {$rawKey}"] : [];

    return test()->getJson('/api/v1/audit-events/health', $headers);
}

// ---------------------------------------------------------------------------
// Action — healthy states
// ---------------------------------------------------------------------------

it('reports healthy for a merchant-less empty audit table', function () {
    $result = auditHealthCheck();

    expect($result->healthy)->toBeTrue()
        ->and($result->retentionConfigValid)->toBeTrue()
        ->and($result->retentionDays)->toBe(365)
        ->and($result->staleCount)->toBe(0)
        ->and($result->newestEventAt)->toBeNull()
        ->and($result->reason)->toBeNull()
        ->and($result->checkedAt)->toBeInstanceOf(CarbonImmutable::class);
});

it('reports healthy with recent audit events and the newest timestamp', function () {
    $merchant = auditHealthMerchant();
    $newest = now()->startOfSecond();
    auditHealthEvent($merchant, ['performed_at' => now()->subDays(2)]);
    auditHealthEvent($merchant, ['performed_at' => $newest]);

    $result = auditHealthCheck();

    expect($result->healthy)->toBeTrue()
        ->and($result->staleCount)->toBe(0)
        ->and($result->newestEventAt?->toISOString())->toBe($newest->toISOString())
        ->and($result->reason)->toBeNull();
});

it('reports stale events as unhealthy without deleting them', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditHealthMerchant();
    auditHealthEvent($merchant, ['performed_at' => now()->subDays(31)]);
    auditHealthEvent($merchant, ['performed_at' => now()->subDays(400)]);
    auditHealthEvent($merchant);

    $result = auditHealthCheck();

    expect($result->healthy)->toBeFalse()
        ->and($result->retentionConfigValid)->toBeTrue()
        ->and($result->staleCount)->toBe(2)
        ->and($result->reason)->toBe('stale_events_present')
        // Health never deletes: both stale events still exist.
        ->and(AuditEvent::count())->toBe(3);
});

it('uses strict cutoff semantics consistent with pruning', function () {
    config(['audit.retention.days' => 30]);
    // Freeze at a microsecond-free instant so the cutoff survives the
    // timestamp(0) column round-trip exactly.
    $this->travelTo($frozen = now()->startOfSecond());
    $merchant = auditHealthMerchant();

    // Exactly AT the cutoff: never stale (strictly-older semantics).
    auditHealthEvent($merchant, ['performed_at' => $frozen->copy()->subDays(30)]);
    // One second older: stale.
    auditHealthEvent($merchant, ['performed_at' => $frozen->copy()->subDays(30)->subSecond()]);

    $result = auditHealthCheck();

    expect($result->staleCount)->toBe(1)
        ->and($result->healthy)->toBeFalse()
        ->and($result->newestEventAt?->toISOString())->toBe($frozen->copy()->subDays(30)->toISOString());
});

// ---------------------------------------------------------------------------
// Lifecycle metrics
// ---------------------------------------------------------------------------

it('distinguishes active and archived counts in the health result', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditHealthMerchant();

    $staleOld = auditHealthEvent($merchant, ['performed_at' => now()->subDays(400)]); // stale + will be archived
    auditHealthEvent($merchant); // recent active
    $stale = auditHealthEvent($merchant, ['performed_at' => now()->subDays(31)]);

    $result = auditHealthCheck();

    expect($result->staleCount)->toBe(2)
        ->and($result->archivedCount)->toBe(0)
        ->and($result->pruneEligibleCount)->toBe(0);

    // Archive the stale events.
    $staleOld->delete(); // soft-delete = simulated/actual archive
    $stale->delete();

    $after = auditHealthCheck();

    expect($after->staleCount)->toBe(0)
        ->and($after->archivedCount)->toBe(2);
});

it('reports prune-eligible count for archived events older than twice the window', function () {
    config(['audit.retention.days' => 30]);
    $this->travelTo(now()->startOfSecond());

    $merchant = auditHealthMerchant();
    $old = auditHealthEvent($merchant, ['performed_at' => now()->subDays(400)]);
    $recent = auditHealthEvent($merchant, ['performed_at' => now()->subDays(31)]);

    $old->delete(); // archive: deleted_at ≈ now

    $result = auditHealthCheck();

    // Prune cutoff = now - 30 days. The archived event's deleted_at (≈now)
    // is NOT older than the prune cutoff, so 0 prune-eligible.
    expect($result->archivedCount)->toBe(1)
        ->and($result->pruneEligibleCount)->toBe(0);

    // Manually age the archive time beyond the prune cutoff.
    $old->forceFill(['deleted_at' => now()->subDays(31)])->save();

    $re = auditHealthCheck();

    expect($re->archivedCount)->toBe(1)
        ->and($re->pruneEligibleCount)->toBe(1);
});

it('reports newest active and archived timestamps independently', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditHealthMerchant();

    $recent = auditHealthEvent($merchant, ['performed_at' => now()->subDay()]);
    $archived = auditHealthEvent($merchant, ['performed_at' => now()->subDays(31), 'deleted_at' => now()->subDay()]);

    $result = auditHealthCheck();

    expect($result->newestEventAt?->toISOString())->toBe($recent->performed_at->toISOString())
        ->and($result->newestArchivedAt?->toISOString())->toBe($archived->deleted_at->toISOString());
});

it('reports null archived metrics for a table with no archived events', function () {
    $result = auditHealthCheck();

    expect($result->archivedCount)->toBe(0)
        ->and($result->pruneEligibleCount)->toBe(0)
        ->and($result->newestArchivedAt)->toBeNull()
        ->and($result->newestEventAt)->toBeNull();
});

it('aggregates archived events across multiple merchants without leaking merchant data', function () {
    config(['audit.retention.days' => 30]);

    $mA = auditHealthMerchant('Merchant A');
    $mB = auditHealthMerchant('Merchant B');

    $oldA = auditHealthEvent($mA, ['performed_at' => now()->subDays(400)]);
    $oldB = auditHealthEvent($mB, ['performed_at' => now()->subDays(400)]);
    auditHealthEvent($mA); // recent active

    $oldA->delete();
    $oldB->delete();

    $result = auditHealthCheck();

    expect($result->archivedCount)->toBe(2)
        ->and($result->staleCount)->toBe(0)
        ->and($result->healthy)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Action — fail-safe behavior
// ---------------------------------------------------------------------------

it('fails safely on invalid retention configuration', function (mixed $days) {
    config(['audit.retention.days' => $days]);

    $result = auditHealthCheck();

    expect($result->healthy)->toBeFalse()
        ->and($result->retentionConfigValid)->toBeFalse()
        ->and($result->retentionDays)->toBeNull()
        // The database was never queried: no counts to report.
        ->and($result->staleCount)->toBeNull()
        ->and($result->reason)->toBe('retention_config_invalid');
})->with([
    'zero' => 0,
    'negative' => -3,
    'non-numeric' => 'soon',
])->after(function () {
    expect(AuditEvent::count())->toBe(0);
});

it('fails safely when the database query fails', function () {
    $merchant = auditHealthMerchant();
    auditHealthEvent($merchant);

    $thrown = false;

    // Simulate an unavailable database: the first audit_events query
    // throws. The action must surface a coarse reason, never internals.
    DB::listen(function ($query) use (&$thrown): void {
        if (! $thrown && str_contains($query->sql, 'from "audit_events"')) {
            $thrown = true;
            throw new QueryException('sqlite', $query->sql, $query->bindings, new Exception('connection refused'));
        }
    });

    $result = auditHealthCheck();

    expect($result->healthy)->toBeFalse()
        ->and($result->retentionConfigValid)->toBeTrue()
        ->and($result->staleCount)->toBeNull()
        ->and($result->newestEventAt)->toBeNull()
        ->and($result->reason)->toBe('database_unavailable');
});

// ---------------------------------------------------------------------------
// Command
// ---------------------------------------------------------------------------

it('exits successfully and prints aggregates when healthy', function () {
    auditHealthEvent(auditHealthMerchant());

    $exitCode = Artisan::call('audit:health');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Audit health: HEALTHY')
        ->and($output)->toContain('Retention configuration: valid')
        ->and($output)->toContain('Events older than the archive cutoff: 0')
        // Aggregate-only: never event references or merchant identity.
        ->and($output)->not->toContain('evt_')
        ->and($output)->not->toContain('pay_')
        ->and($output)->not->toContain('Health Merchant');
});

it('exits with failure and a coarse reason when stale events exist', function () {
    config(['audit.retention.days' => 30]);
    auditHealthEvent(auditHealthMerchant(), ['performed_at' => now()->subDays(31)]);

    $exitCode = Artisan::call('audit:health');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Audit health: UNHEALTHY')
        ->and($output)->toContain('Events older than the archive cutoff: 1')
        ->and($output)->toContain('Reason: stale_events_present');
});

it('exits with failure and never leaks internals on invalid configuration', function () {
    config(['audit.retention.days' => 0]);

    $exitCode = Artisan::call('audit:health');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Audit health: UNHEALTHY')
        ->and($output)->toContain('Retention configuration: INVALID')
        ->and($output)->toContain('Reason: retention_config_invalid')
        // The exception message itself is never printed.
        ->and($output)->not->toContain('Invalid audit retention configuration');
});

it('emits machine-readable JSON output on request', function () {
    $exitCode = Artisan::call('audit:health', ['--json' => true]);
    $output = Artisan::output();

    $decoded = json_decode(trim($output), true);

    expect($exitCode)->toBe(0)
        ->and($decoded)->toBeArray()
        ->and(array_keys($decoded))->toBe([
            'healthy', 'retention_config_valid', 'retention_days',
            'stale_events', 'archived_events', 'prune_eligible_events',
            'newest_event_at', 'newest_archived_at',
            'checked_at', 'reason',
        ])
        ->and($decoded['healthy'])->toBeTrue()
        ->and($decoded['stale_events'])->toBe(0)
        ->and($decoded['reason'])->toBeNull();
});

// ---------------------------------------------------------------------------
// HTTP endpoint
// ---------------------------------------------------------------------------

it('requires authentication for the health endpoint', function () {
    auditHealthHttp(null)->assertUnauthorized()->assertJson(['message' => 'Invalid API key.']);
    auditHealthHttp('sk_test_'.Str::random(40))->assertUnauthorized();
});

it('returns the aggregate health snapshot over HTTP', function () {
    $merchant = auditHealthMerchant();
    $rawKey = app(CreateApiKey::class)->create($merchant, 'health')->rawKey;
    auditHealthEvent($merchant);

    $response = auditHealthHttp($rawKey)->assertOk();
    $data = $response->json('data');

    expect($data['healthy'])->toBeTrue()
        ->and($data['retention_config_valid'])->toBeTrue()
        ->and($data['retention_days'])->toBe(365)
        ->and($data['stale_events'])->toBe(0)
        ->and($data['newest_event_at'])->not->toBeNull()
        ->and($data['checked_at'])->not->toBeNull()
        ->and($data['reason'])->toBeNull();
});

it('exposes only the whitelisted health fields', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditHealthMerchant();
    $rawKey = app(CreateApiKey::class)->create($merchant, 'health')->rawKey;
    auditHealthEvent($merchant, [
        'metadata' => ['api_key' => 'sk_test_health_secret'],
        'performed_at' => now()->subDays(31),
    ]);

    $response = auditHealthHttp($rawKey)->assertOk();
    $data = $response->json('data');

    expect(array_keys($data))->toBe([
        'healthy', 'retention_config_valid', 'retention_days',
        'stale_events', 'archived_events', 'prune_eligible_events',
        'newest_event_at', 'newest_archived_at',
        'checked_at', 'reason',
    ])->and($data['healthy'])->toBeFalse()
        ->and($data['stale_events'])->toBe(1)
        ->and($data['reason'])->toBe('stale_events_present');

    $serialized = $response->getContent() ?? '';

    expect($serialized)->not->toContain('sk_test_health_secret')
        ->and($serialized)->not->toContain('evt_')
        ->and($serialized)->not->toContain('pay_')
        ->and($serialized)->not->toContain('merchant_id')
        ->and($serialized)->not->toContain('metadata');
});

it('returns merchant-agnostic health to every authenticated caller', function () {
    // Merchant A has events; Merchant B has none — but health is global
    // operational state, so both callers see the identical snapshot.
    $merchantA = auditHealthMerchant('Merchant A');
    auditHealthEvent($merchantA);
    $keyA = app(CreateApiKey::class)->create($merchantA, 'health')->rawKey;

    $merchantB = auditHealthMerchant('Merchant B');
    $keyB = app(CreateApiKey::class)->create($merchantB, 'health')->rawKey;

    $responseA = auditHealthHttp($keyA)->assertOk()->json('data');
    $responseB = auditHealthHttp($keyB)->assertOk()->json('data');

    // checked_at carries millisecond precision — compare everything else.
    $withoutCheckedAt = fn (array $data): array => array_diff_key($data, ['checked_at' => null]);

    expect($withoutCheckedAt($responseA))->toEqual($withoutCheckedAt($responseB))
        ->and($responseA['stale_events'])->toBe($responseB['stale_events'])
        ->and($responseA['healthy'])->toBe($responseB['healthy']);
});

it('routes health through the standard bucket before the reference route', function () {
    $health = Route::getRoutes()->getByName('api.v1.audit-events.health');
    $show = Route::getRoutes()->getByName('api.v1.audit-events.show');

    expect($health->gatherMiddleware())->toContain('api.key', 'throttle:standard')
        ->and($health->gatherMiddleware())->not->toContain('throttle:export', 'throttle:sensitive');

    $order = collect(Route::getRoutes()->getRoutes())->map->getName();

    expect($order->search('api.v1.audit-events.health'))
        ->toBeLessThan($order->search('api.v1.audit-events.show'));
});

// ---------------------------------------------------------------------------
// Audit recursion prevention
// ---------------------------------------------------------------------------

it('creates zero new audit events for repeated health checks', function () {
    $merchant = auditHealthMerchant();
    auditHealthEvent($merchant);
    $rawKey = app(CreateApiKey::class)->create($merchant, 'health')->rawKey;

    $before = AuditEvent::count();

    auditHealthCheck();
    auditHealthCheck();
    auditHealthHttp($rawKey)->assertOk();
    auditHealthHttp($rawKey)->assertOk();
    Artisan::call('audit:health');
    Artisan::call('audit:health', ['--json' => true]);

    expect(AuditEvent::count())->toBe($before);
});

// ---------------------------------------------------------------------------
// Query behavior
// ---------------------------------------------------------------------------

it('aggregates health with bounded queries and no row loading', function () {
    $merchant = auditHealthMerchant();
    collect(range(1, 10))->each(fn () => auditHealthEvent($merchant));

    $selects = [];

    DB::listen(function ($query) use (&$selects): void {
        if (str_contains($query->sql, 'from "audit_events"')
            && str_starts_with(trim($query->sql), 'select')) {
            $selects[] = $query->sql;
        }
    });

    auditHealthCheck();

    // Exactly five audit_events queries: stale COUNT, active MAX,
    // archived COUNT, prune-eligible COUNT, archived MAX — all aggregates,
    // all no row hydration.
    expect(count($selects))->toBe(5);

    foreach ($selects as $sql) {
        expect(str_contains(strtolower($sql), 'count(*)') || str_contains(strtolower($sql), 'max('))
            ->toBeTrue("Non-aggregate audit_events select executed: {$sql}");
    }
});

// ---------------------------------------------------------------------------
// Regression
// ---------------------------------------------------------------------------

it('regression: pruning resolves the stale condition health reports', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditHealthMerchant();
    auditHealthEvent($merchant, ['performed_at' => now()->subDays(31)]);
    auditHealthEvent($merchant);

    expect(auditHealthCheck()->healthy)->toBeFalse()
        ->and(auditHealthCheck()->staleCount)->toBe(1);

    // Two-stage lifecycle: stale active events must be archived first,
    // then prune permanently removes the archived rows.
    app(ArchiveAuditEvents::class)->execute();

    expect(auditHealthCheck()->staleCount)->toBe(0)
        ->and(auditHealthCheck()->archivedCount)->toBe(1)
        ->and(AuditEvent::withTrashed()->count())->toBe(2);

    app(PruneAuditEvents::class)->execute(retentionDays: 30);

    // Grace window: the row was archived moments ago, so its deleted_at is
    // far newer than the prune cutoff — prune must NOT delete it yet.
    expect(auditHealthCheck()->staleCount)->toBe(0)
        ->and(auditHealthCheck()->archivedCount)->toBe(1)
        ->and(AuditEvent::withTrashed()->count())->toBe(2); // recent active + archived row retained
});

it('regression: metrics and retrieval endpoints remain unaffected', function () {
    $merchant = auditHealthMerchant();
    $rawKey = app(CreateApiKey::class)->create($merchant, 'regression')->rawKey;
    $headers = ['Authorization' => "Bearer {$rawKey}"];
    auditHealthEvent($merchant);

    // Health does not disturb the metrics endpoint.
    $metrics = $this->getJson('/api/v1/audit-events/metrics', $headers)->assertOk();
    expect($metrics->json('data.total'))->toBe(1);

    // ...nor the list endpoint.
    $list = $this->getJson('/api/v1/audit-events', $headers)->assertOk();
    expect($list->json('meta.total'))->toBe(1);
});
