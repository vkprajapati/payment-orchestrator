<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Actions\Audit\PruneAuditEvents;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Exceptions\InvalidAuditRetentionException;
use App\Models\AuditEvent;
use App\Models\IdempotencyKey;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\Refund;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * (auditPrune-prefixed helpers avoid clashing with sibling test files
 * under the same Pest process.)
 */
function auditPruneMerchant(string $name): Merchant
{
    return Merchant::factory()->create(['name' => $name]);
}

/**
 * Directly create an audit event with a controlled performed_at instant.
 *
 * @param  array<string, mixed>  $extra
 */
function auditPruneEvent(Merchant $merchant, CarbonInterface $performedAt, array $extra = []): AuditEvent
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
        'metadata' => ['amount' => 1000],
        'performed_at' => $performedAt,
    ], $extra));
}

function auditPruneOld(Merchant $merchant, int $daysOld = 31, array $extra = []): AuditEvent
{
    return auditPruneEvent($merchant, now()->subDays($daysOld), $extra);
}

function auditPruneRecent(Merchant $merchant, array $extra = []): AuditEvent
{
    return auditPruneEvent($merchant, now(), $extra);
}

// ---------------------------------------------------------------------------
// Retention behavior
// ---------------------------------------------------------------------------

it('deletes events strictly older than the retention cutoff and keeps newer ones', function () {
    $merchant = auditPruneMerchant('Prune Co');
    auditPruneOld($merchant, daysOld: 31);
    $kept = auditPruneRecent($merchant);

    $result = app(PruneAuditEvents::class)->execute(retentionDays: 30);

    expect($result->deleted)->toBe(1)
        ->and($result->eligible)->toBe(1)
        ->and($result->batches)->toBe(1)
        ->and(AuditEvent::count())->toBe(1)
        ->and(AuditEvent::latest('id')->first()->reference)->toBe($kept->reference);
});

it('keeps an event exactly at the cutoff (strictly-older semantics)', function () {
    // Freeze time at a microsecond-free instant so the cutoff survives
    // the timestamp(0) column round-trip exactly.
    $this->travelTo($frozen = now()->startOfSecond());
    $merchant = auditPruneMerchant('Prune Co');

    // performed_at exactly equal to the cutoff must remain.
    auditPruneEvent($merchant, $frozen->copy()->subDays(30));
    // One second older must be deleted.
    auditPruneEvent($merchant, $frozen->copy()->subDays(30)->subSecond());

    $result = app(PruneAuditEvents::class)->execute(retentionDays: 30);

    expect($result->deleted)->toBe(1)
        ->and(AuditEvent::count())->toBe(1)
        ->and(AuditEvent::latest('id')->first()->performed_at->equalTo($frozen->copy()->subDays(30)))->toBeTrue();
});

it('prunes only eligible rows from a mixed old and new set', function () {
    $merchant = auditPruneMerchant('Prune Co');

    $old = collect(range(1, 3))->map(fn () => auditPruneOld($merchant));
    $new = collect(range(1, 2))->map(fn () => auditPruneRecent($merchant));

    $result = app(PruneAuditEvents::class)->execute(retentionDays: 30);

    expect($result->deleted)->toBe(3)
        ->and(AuditEvent::count())->toBe(2)
        ->and(AuditEvent::pluck('reference')->sort()->values()->all())
        ->toBe($new->pluck('reference')->sort()->values()->all())
        ->and(collect(AuditEvent::pluck('reference'))->intersect($old->pluck('reference'))->isNotEmpty())->toBeFalse();
});

it('is a safe no-op when nothing is eligible', function () {
    $merchant = auditPruneMerchant('Prune Co');
    auditPruneRecent($merchant);

    $result = app(PruneAuditEvents::class)->execute(retentionDays: 30);

    expect($result->deleted)->toBe(0)
        ->and($result->eligible)->toBe(0)
        ->and($result->batches)->toBe(0)
        ->and(AuditEvent::count())->toBe(1);
});

it('uses performed_at — not created_at — as the retention clock', function () {
    $merchant = auditPruneMerchant('Prune Co');

    // Old row-insert time but a RECENT performed_at: must be retained,
    // proving the documented semantic choice of field.
    $recent = auditPruneRecent($merchant);
    DB::table('audit_events')->where('id', $recent->id)->update([
        'created_at' => now()->subDays(400),
    ]);
    $recent->refresh();

    // Recent row-insert time but an OLD performed_at: must be pruned.
    $stale = auditPruneOld($merchant);
    DB::table('audit_events')->where('id', $stale->id)->update([
        'created_at' => now(),
    ]);

    app(PruneAuditEvents::class)->execute(retentionDays: 30);

    expect(AuditEvent::count())->toBe(1)
        ->and(AuditEvent::latest('id')->first()->reference)->toBe($recent->reference);
});

// ---------------------------------------------------------------------------
// Batch behavior
// ---------------------------------------------------------------------------

it('fully prunes more events than a single batch and reports the count correctly', function () {
    $merchant = auditPruneMerchant('Prune Co');
    collect(range(1, 25))->each(fn () => auditPruneOld($merchant));

    $result = app(PruneAuditEvents::class)->execute(retentionDays: 30, batchSize: 10);

    expect($result->deleted)->toBe(25)
        ->and($result->batches)->toBe(3)
        ->and(AuditEvent::count())->toBe(0);
});

it('does not skip records when rows disappear between batches', function () {
    $merchant = auditPruneMerchant('Prune Co');
    collect(range(1, 25))->each(fn () => auditPruneOld($merchant));

    $cutoff = now()->subDays(30);
    $removedConcurrently = 0;
    $injected = false;

    // Simulate concurrent deletion: when the FIRST prune batch delete
    // executes, another "process" removes 5 more eligible rows directly.
    DB::listen(function ($query) use (&$removedConcurrently, &$injected, $cutoff): void {
        if ($injected || ! str_starts_with($query->sql, 'delete from "audit_events"')) {
            return;
        }

        // Set the flag BEFORE issuing our own delete so this listener is
        // not re-entered by the queries it triggers.
        $injected = true;

        $ids = DB::table('audit_events')
            ->where('performed_at', '<', $cutoff)
            ->limit(5)
            ->pluck('id');
        $removedConcurrently = DB::table('audit_events')->whereIn('id', $ids)->delete();
    });

    $result = app(PruneAuditEvents::class)->execute(retentionDays: 30, batchSize: 10);

    expect($removedConcurrently)->toBe(5)
        // The action only reports rows IT deleted (20); the 5 removed
        // concurrently are not double-counted.
        ->and($result->deleted)->toBe(20)
        // And critically: no eligible row was skipped — table is empty.
        ->and(AuditEvent::where('performed_at', '<', $cutoff)->count())->toBe(0)
        ->and(AuditEvent::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Merchant coverage
// ---------------------------------------------------------------------------

it('prunes old events across all merchants and keeps recent events for all', function () {
    $merchantA = auditPruneMerchant('Merchant A');
    $merchantB = auditPruneMerchant('Merchant B');
    $merchantC = auditPruneMerchant('Merchant C');

    $oldA = auditPruneOld($merchantA);
    $oldB = auditPruneOld($merchantB, daysOld: 500);
    $newA = auditPruneRecent($merchantA);
    $newB = auditPruneRecent($merchantB);
    $newC = auditPruneRecent($merchantC);

    $result = app(PruneAuditEvents::class)->execute(retentionDays: 30);

    expect($result->deleted)->toBe(2)
        ->and(AuditEvent::count())->toBe(3)
        ->and(AuditEvent::pluck('reference'))->toContain($newA->reference, $newB->reference, $newC->reference)
        ->and(AuditEvent::pluck('reference'))->not->toContain($oldA->reference, $oldB->reference);
});

it('leaves unrelated tables and rows completely untouched', function () {
    $merchant = auditPruneMerchant('Prune Co');
    auditPruneOld($merchant);
    auditPruneRecent($merchant);

    $payment = Payment::factory()->for($merchant)->create();
    $refund = Refund::factory()->for($payment)->for($merchant, 'merchant')->create();
    $idempotencyKey = IdempotencyKey::factory()->for($merchant)->create();
    $otherMerchant = auditPruneMerchant('Survivor Co');

    $before = collect([
        'merchants' => DB::table('merchants')->count(),
        'payments' => DB::table('payments')->count(),
        'refunds' => DB::table('refunds')->count(),
        'idempotency_keys' => DB::table('idempotency_keys')->count(),
        'api_keys' => DB::table('api_keys')->count(),
    ]);

    app(PruneAuditEvents::class)->execute(retentionDays: 30);

    $after = collect([
        'merchants' => DB::table('merchants')->count(),
        'payments' => DB::table('payments')->count(),
        'refunds' => DB::table('refunds')->count(),
        'idempotency_keys' => DB::table('idempotency_keys')->count(),
        'api_keys' => DB::table('api_keys')->count(),
    ]);

    expect($after)->toEqual($before)
        ->and(DB::table('payments')->where('id', $payment->id)->exists())->toBeTrue()
        ->and(DB::table('refunds')->where('id', $refund->id)->exists())->toBeTrue()
        ->and(DB::table('idempotency_keys')->where('id', $idempotencyKey->id)->exists())->toBeTrue()
        ->and(DB::table('merchants')->where('id', $otherMerchant->id)->exists())->toBeTrue()
        // Only the eligible audit rows were removed; the FK cascade
        // (merchant deletion) is never triggered by pruning.
        ->and(AuditEvent::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

it('honors a custom retention period from configuration', function () {
    config(['audit.retention.days' => 7]);
    $merchant = auditPruneMerchant('Prune Co');
    auditPruneOld($merchant, daysOld: 8);
    $kept = auditPruneRecent($merchant);

    $result = app(PruneAuditEvents::class)->execute();

    expect($result->retentionDays)->toBe(7)
        ->and($result->deleted)->toBe(1)
        ->and(AuditEvent::pluck('reference'))->toContain($kept->reference);
});

it('honors a custom batch size from configuration', function () {
    config(['audit.retention.batch_size' => 2]);
    $merchant = auditPruneMerchant('Prune Co');
    collect(range(1, 5))->each(fn () => auditPruneOld($merchant));

    $result = app(PruneAuditEvents::class)->execute(retentionDays: 30);

    expect($result->batchSize)->toBe(2)
        ->and($result->deleted)->toBe(5)
        ->and($result->batches)->toBe(3)
        ->and(AuditEvent::count())->toBe(0);
});

it('fails safely on zero, negative, and non-numeric retention configuration', function (mixed $days) {
    config(['audit.retention.days' => $days]);
    auditPruneOld(auditPruneMerchant('Prune Co'));

    app(PruneAuditEvents::class)->execute();
})->throws(InvalidAuditRetentionException::class)->with([
    'zero days' => 0,
    'negative days' => -5,
    'non-numeric days' => 'thirty',
    'null days' => null,
])->after(function () {
    // Nothing was ever deleted with an invalid configuration.
    expect(AuditEvent::count())->toBe(1);
});

it('fails safely on invalid batch size configuration', function (mixed $batchSize) {
    config(['audit.retention.batch_size' => $batchSize]);
    auditPruneOld(auditPruneMerchant('Prune Co'));

    app(PruneAuditEvents::class)->execute(retentionDays: 30);
})->throws(InvalidAuditRetentionException::class)->with([
    'zero batch size' => 0,
    'negative batch size' => -10,
    'non-numeric batch size' => 'lots',
]);

it('accepts numeric-string configuration from environment variables', function () {
    // env() values arrive as strings — these must work.
    config(['audit.retention.days' => '14', 'audit.retention.batch_size' => '50']);
    $merchant = auditPruneMerchant('Prune Co');
    auditPruneOld($merchant, daysOld: 15);

    $result = app(PruneAuditEvents::class)->execute();

    expect($result->retentionDays)->toBe(14)
        ->and($result->batchSize)->toBe(50)
        ->and($result->deleted)->toBe(1);
});

// ---------------------------------------------------------------------------
// Artisan command
// ---------------------------------------------------------------------------

it('prunes via the audit:prune command and reports aggregate counts only', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditPruneMerchant('Prune Co');
    $old = auditPruneEvent($merchant, now()->subDays(31), [
        'metadata' => ['amount' => 9999, 'api_key' => 'sk_test_leaky_secret'],
    ]);
    auditPruneRecent($merchant);

    $exitCode = Artisan::call('audit:prune');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Pruned 1 audit event(s) in 1 batch(es).')
        ->and($output)->toContain('Audit retention window: 30 day(s)');

    // Aggregate-only output: no event references, no merchant identity,
    // no metadata or secrets ever leak into CLI output.
    expect($output)->not->toContain('evt_')
        ->and($output)->not->toContain('pay_')
        ->and($output)->not->toContain('Prune Co')
        ->and($output)->not->toContain('sk_test_leaky_secret')
        ->and($output)->not->toContain('9999');

    expect(AuditEvent::count())->toBe(1)
        ->and(AuditEvent::latest('id')->first()->reference)->not->toBe($old->reference);
});

it('succeeds with a clean message when there is nothing to prune', function () {
    auditPruneRecent(auditPruneMerchant('Prune Co'));

    $exitCode = Artisan::call('audit:prune');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Pruned 0 audit event(s) in 0 batch(es).')
        ->and(AuditEvent::count())->toBe(1);
});

it('supports a --days override on the command', function () {
    $merchant = auditPruneMerchant('Prune Co');
    auditPruneEvent($merchant, now()->subDays(8));
    auditPruneRecent($merchant);

    $exitCode = Artisan::call('audit:prune', ['--days' => '7']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Audit retention window: 7 day(s)')
        ->and($output)->toContain('Pruned 1 audit event(s) in 1 batch(es).')
        ->and(AuditEvent::count())->toBe(1);
});

it('fails safely on an invalid --days override', function () {
    auditPruneOld(auditPruneMerchant('Prune Co'));
    $before = AuditEvent::count();

    $exitCode = Artisan::call('audit:prune', ['--days' => 'not-a-number']);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Invalid audit retention configuration')
        ->and($output)->toContain('Nothing was deleted')
        ->and(AuditEvent::count())->toBe($before);
});

// ---------------------------------------------------------------------------
// Dry run
// ---------------------------------------------------------------------------

it('reports what a dry run would delete without deleting anything', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditPruneMerchant('Prune Co');
    collect(range(1, 3))->each(fn () => auditPruneOld($merchant));
    auditPruneRecent($merchant);

    $exitCode = Artisan::call('audit:prune', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Dry run: 3 audit event(s) would be deleted. Nothing was deleted.')
        ->and(AuditEvent::count())->toBe(4);

    // Action-level dry run reports the same aggregate.
    $result = app(PruneAuditEvents::class)->execute(retentionDays: 30, dryRun: true);

    expect($result->dryRun)->toBeTrue()
        ->and($result->eligible)->toBe(3)
        ->and($result->deleted)->toBe(0)
        ->and($result->batches)->toBe(0)
        ->and(AuditEvent::count())->toBe(4);
});

// ---------------------------------------------------------------------------
// Concurrency / cutoff safety
// ---------------------------------------------------------------------------

it('never deletes events written concurrently after the cutoff was computed', function () {
    // Freeze time so the cutoff and the concurrent write are deterministic.
    $this->travelTo(now()->startOfSecond());
    $merchant = auditPruneMerchant('Prune Co');
    collect(range(1, 5))->each(fn () => auditPruneEvent($merchant, now()->subDays(31)));

    $concurrent = null;

    // Simulate a concurrent audit write DURING the pruning run: the first
    // batch delete triggers a new event inserted with performed_at = now().
    DB::listen(function ($query) use (&$concurrent, $merchant): void {
        if (str_starts_with($query->sql, 'delete from "audit_events"') && $concurrent === null) {
            $concurrent = auditPruneRecent($merchant);
        }
    });

    $result = app(PruneAuditEvents::class)->execute(retentionDays: 30);

    expect($concurrent)->toBeInstanceOf(AuditEvent::class)
        // All pre-cutoff events are gone...
        ->and($result->deleted)->toBe(5)
        // ...and the event written mid-run survives: the cutoff was
        // computed once, before any deletion, so the concurrent write is
        // strictly newer than the cutoff.
        ->and(AuditEvent::count())->toBe(1)
        ->and(AuditEvent::latest('id')->first()->reference)->toBe($concurrent->reference);
});

// ---------------------------------------------------------------------------
// Audit recursion prevention & regression
// ---------------------------------------------------------------------------

it('creates zero new audit events while pruning', function () {
    $merchant = auditPruneMerchant('Prune Co');
    auditPruneOld($merchant);
    auditPruneRecent($merchant);
    $before = AuditEvent::count();

    $result = app(PruneAuditEvents::class)->execute(retentionDays: 30);

    expect($result->deleted)->toBe(1)
        // Pruning itself must not call AuditLogger or create audit rows.
        ->and(AuditEvent::count())->toBe($before - $result->deleted);
});

it('leaves audit reads and exports untouched by the pruning code path', function () {
    // The prune action shares no code with the retrieval/export endpoints;
    // this test proves the export and list endpoints still behave after a
    // prune run has executed.
    config(['audit.export.max_events' => 100]);
    $merchant = auditPruneMerchant('Prune Co');
    auditPruneOld($merchant, extra: ['http_method' => 'POST', 'path' => 'api/v1/payments']);
    $recent = auditPruneRecent($merchant);

    app(PruneAuditEvents::class)->execute(retentionDays: 30);

    $rawKey = app(CreateApiKey::class)->create($merchant, 'regression')->rawKey;
    $headers = ['Authorization' => "Bearer {$rawKey}"];

    $list = $this->getJson('/api/v1/audit-events', $headers)->assertOk();
    expect($list->json('data.0.reference'))->toBe($recent->reference);

    $export = $this->getJson('/api/v1/audit-events/export', $headers)->assertOk();
    expect(count($export->json('data')))->toBe(1);
});

it('regression: payment flow audit logging still works after pruning exists', function () {
    $merchant = auditPruneMerchant('Prune Co');
    $rawKey = app(CreateApiKey::class)->create($merchant, 'regression')->rawKey;
    $headers = ['Authorization' => "Bearer {$rawKey}", 'Idempotency-Key' => 'prune-regression-1'];
    $payload = ['amount' => 1000, 'currency' => 'USD'];

    $this->postJson('/api/v1/payments', $payload, $headers)->assertCreated();
    // Idempotent replay: still exactly one payment.created audit event.
    $this->postJson('/api/v1/payments', $payload, $headers)->assertCreated();

    expect(AuditEvent::where('event', AuditEventName::PaymentCreated->value)->count())->toBe(1);

    // Pruning with the default window must not touch these fresh events.
    app(PruneAuditEvents::class)->execute();

    expect(AuditEvent::where('event', AuditEventName::PaymentCreated->value)->count())->toBe(1)
        ->and(AuditEvent::count())->toBe(1);
});
