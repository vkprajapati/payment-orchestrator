<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Actions\Audit\ArchiveAuditEvents;
use App\Actions\Audit\PruneAuditEvents;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Exceptions\InvalidAuditRetentionException;
use App\Models\AuditEvent;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

/**
 * (auditArchive-prefixed helpers avoid clashing with sibling test files
 * under the same Pest process.)
 */
function auditArchiveMerchant(string $name = 'Archive Merchant'): Merchant
{
    return Merchant::factory()->create(['name' => $name]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function auditArchiveEvent(Merchant $merchant, array $attributes = []): AuditEvent
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

function auditArchiveKey(Merchant $merchant): string
{
    return app(CreateApiKey::class)->create($merchant, 'archive')->rawKey;
}

// ---------------------------------------------------------------------------
// Archival eligibility
// ---------------------------------------------------------------------------

it('archives active events older than the archive cutoff and keeps recent ones', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditArchiveMerchant();

    $old = auditArchiveEvent($merchant, ['performed_at' => now()->subDays(400)]);
    $recent = auditArchiveEvent($merchant);

    $result = app(ArchiveAuditEvents::class)->execute();

    expect($result->eligible)->toBe(1)
        ->and($result->archived)->toBe(1)
        ->and($result->batches)->toBe(1)
        ->and($old->refresh()->deleted_at)->not->toBeNull()
        ->and($recent->refresh()->deleted_at)->toBeNull()
        ->and(AuditEvent::count())->toBe(1) // active rows
        ->and(AuditEvent::withTrashed()->count())->toBe(2);
});

it('keeps an active event exactly at the archive cutoff (strict semantics)', function () {
    config(['audit.retention.days' => 30]);
    $this->travelTo($frozen = now()->startOfSecond());
    $merchant = auditArchiveMerchant();

    $boundary = auditArchiveEvent($merchant, ['performed_at' => $frozen->copy()->subDays(30)]);
    $older = auditArchiveEvent($merchant, ['performed_at' => $frozen->copy()->subDays(30)->subSecond()]);

    app(ArchiveAuditEvents::class)->execute();

    expect($boundary->refresh()->deleted_at)->toBeNull()
        ->and($older->refresh()->deleted_at)->not->toBeNull();
});

it('is idempotent — re-running never re-archives or double-counts', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditArchiveMerchant();
    auditArchiveEvent($merchant, ['performed_at' => now()->subDays(400)]);

    $first = app(ArchiveAuditEvents::class)->execute();
    $second = app(ArchiveAuditEvents::class)->execute();

    expect($first->archived)->toBe(1)
        ->and($second->eligible)->toBe(0)
        ->and($second->archived)->toBe(0)
        ->and($second->batches)->toBe(0);
});

it('dry-run reports the eligible count without archiving anything', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditArchiveMerchant();
    auditArchiveEvent($merchant, ['performed_at' => now()->subDays(400)]);
    auditArchiveEvent($merchant);

    $result = app(ArchiveAuditEvents::class)->execute(dryRun: true);

    expect($result->eligible)->toBe(1)
        ->and($result->archived)->toBe(0)
        ->and($result->batches)->toBe(0)
        ->and($result->dryRun)->toBeTrue()
        ->and(AuditEvent::count())->toBe(2); // both still active
});

it('archives in bounded batches across multiple batches', function () {
    config(['audit.retention.days' => 30, 'audit.retention.batch_size' => 2]);
    $merchant = auditArchiveMerchant();
    collect(range(1, 5))->each(fn () => auditArchiveEvent($merchant, ['performed_at' => now()->subDays(400)]));

    $result = app(ArchiveAuditEvents::class)->execute();

    expect($result->archived)->toBe(5)
        ->and($result->batches)->toBe(3)
        ->and(AuditEvent::count())->toBe(0);
});

it('archives across multiple merchants without cross-merchant leakage', function () {
    config(['audit.retention.days' => 30]);
    $merchantA = auditArchiveMerchant('Merchant A');
    $merchantB = auditArchiveMerchant('Merchant B');

    $oldA = auditArchiveEvent($merchantA, ['performed_at' => now()->subDays(400)]);
    $oldB = auditArchiveEvent($merchantB, ['performed_at' => now()->subDays(400)]);
    auditArchiveEvent($merchantA); // recent active

    $result = app(ArchiveAuditEvents::class)->execute();

    expect($result->archived)->toBe(2)
        ->and($oldA->refresh()->deleted_at)->not->toBeNull()
        ->and($oldB->refresh()->deleted_at)->not->toBeNull()
        ->and(AuditEvent::count())->toBe(1);
});

it('fails safely on invalid retention configuration', function (mixed $days) {
    config(['audit.retention.days' => $days]);
    $merchant = auditArchiveMerchant();
    auditArchiveEvent($merchant, ['performed_at' => now()->subDays(400)]);

    app(ArchiveAuditEvents::class)->execute();
})->throws(InvalidAuditRetentionException::class)->with([
    'zero days' => 0,
    'negative days' => -5,
    'non-numeric days' => 'thirty',
])->after(function () {
    // Nothing was ever archived with an invalid configuration.
    expect(AuditEvent::withTrashed()->count())->toBe(1)
        ->and(AuditEvent::whereNotNull('deleted_at')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Artisan command
// ---------------------------------------------------------------------------

it('archives via the audit:archive command and reports aggregate counts only', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditArchiveMerchant();
    auditArchiveEvent($merchant, [
        'performed_at' => now()->subDays(400),
        'metadata' => ['api_key' => 'sk_test_archive_secret'],
    ]);

    $exitCode = Artisan::call('audit:archive');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Archived 1 audit event(s) in 1 batch(es).')
        ->and($output)->toContain('Audit archive window: 30 day(s)');

    // Aggregate-only output: no event references, merchant identity,
    // metadata, or secrets ever leak into CLI output.
    expect($output)->not->toContain('evt_')
        ->and($output)->not->toContain('pay_')
        ->and($output)->not->toContain('Archive Merchant')
        ->and($output)->not->toContain('sk_test_archive_secret');

    expect(AuditEvent::withTrashed()->whereNotNull('deleted_at')->count())->toBe(1);
});

it('audit:archive --dry-run archives nothing and reports the count', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditArchiveMerchant();
    auditArchiveEvent($merchant, ['performed_at' => now()->subDays(400)]);

    $exitCode = Artisan::call('audit:archive', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Dry run: 1 audit event(s) would be archived. Nothing was archived.')
        ->and(AuditEvent::withTrashed()->whereNotNull('deleted_at')->count())->toBe(0);
});

it('audit:archive supports a --days override', function () {
    $merchant = auditArchiveMerchant();
    auditArchiveEvent($merchant, ['performed_at' => now()->subDays(8)]);

    $exitCode = Artisan::call('audit:archive', ['--days' => '7']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Audit archive window: 7 day(s)')
        ->and($output)->toContain('Archived 1 audit event(s) in 1 batch(es).');
});

it('audit:archive fails safely on invalid configuration with a non-zero exit', function () {
    config(['audit.retention.days' => 0]);
    $merchant = auditArchiveMerchant();
    auditArchiveEvent($merchant, ['performed_at' => now()->subDays(400)]);

    $exitCode = Artisan::call('audit:archive');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Nothing was deleted.')
        ->and(AuditEvent::withTrashed()->whereNotNull('deleted_at')->count())->toBe(0);
});

it('schedules audit:archive before audit:prune with withoutOverlapping', function () {
    $events = Schedule::events();

    $archive = collect($events)->first(fn ($event) => str_contains($event->command, 'audit:archive'));
    $prune = collect($events)->first(fn ($event) => str_contains($event->command, 'audit:prune'));

    expect($archive)->not->toBeNull()
        ->and($prune)->not->toBeNull()
        ->and($archive->expression)->toBe('0 1 * * *')
        ->and($prune->expression)->toBe('0 2 * * *')
        ->and($archive->withoutOverlapping)->toBeTrue()
        ->and($prune->withoutOverlapping)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Read-side exclusion — archived events disappear from merchant APIs
// ---------------------------------------------------------------------------

it('archived events disappear from list, show, JSON and CSV export, and metrics', function () {
    config(['audit.retention.days' => 30, 'audit.export.max_events' => 100]);
    $merchant = auditArchiveMerchant();
    $rawKey = auditArchiveKey($merchant);
    $headers = ['Authorization' => "Bearer {$rawKey}"];

    $archived = auditArchiveEvent($merchant, ['performed_at' => now()->subDays(400)]);
    $active = auditArchiveEvent($merchant);

    app(ArchiveAuditEvents::class)->execute();

    // List: only the active event.
    $list = $this->getJson('/api/v1/audit-events', $headers)->assertOk();
    expect($list->json('meta.total'))->toBe(1)
        ->and($list->json('data.0.reference'))->toBe($active->reference);

    // Show: archived reference resolves to the generic 404.
    $this->getJson("/api/v1/audit-events/{$archived->reference}", $headers)
        ->assertNotFound()
        ->assertJson(['message' => 'Not found.']);
    $this->getJson("/api/v1/audit-events/{$active->reference}", $headers)->assertOk();

    // JSON export: only the active event.
    $export = $this->getJson('/api/v1/audit-events/export', $headers)->assertOk();
    expect(count($export->json('data')))->toBe(1);

    // CSV export: only the active event row.
    $csv = $this->getJson('/api/v1/audit-events/export?format=csv', $headers)->assertOk();
    expect($csv->streamedContent())->toContain($active->reference)
        ->and($csv->streamedContent())->not->toContain($archived->reference);

    // Metrics: only the active event is aggregated.
    $metrics = $this->getJson('/api/v1/audit-events/metrics', $headers)->assertOk();
    expect($metrics->json('data.total'))->toBe(1);

    // Forensic access remains available internally (withTrashed).
    expect(AuditEvent::withTrashed()->where('reference', $archived->reference)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Recursion prevention
// ---------------------------------------------------------------------------

it('creates zero new audit events while archiving', function () {
    config(['audit.retention.days' => 30]);
    $merchant = auditArchiveMerchant();
    auditArchiveEvent($merchant, ['performed_at' => now()->subDays(400)]);
    auditArchiveEvent($merchant);

    $before = AuditEvent::withTrashed()->count();

    app(ArchiveAuditEvents::class)->execute();
    Artisan::call('audit:archive');
    Artisan::call('audit:archive', ['--dry-run' => true]);

    // One row was archived (still present), and no new rows appeared.
    expect(AuditEvent::withTrashed()->count())->toBe($before);
});

// ---------------------------------------------------------------------------
// Full lifecycle regression
// ---------------------------------------------------------------------------

it('completes the active → archived → pruned lifecycle end to end', function () {
    config(['audit.retention.days' => 30]);
    $this->travelTo(now()->startOfSecond());
    $merchant = auditArchiveMerchant();
    $event = auditArchiveEvent($merchant, ['performed_at' => now()->subDays(400)]);

    // Stage 1: archive.
    app(ArchiveAuditEvents::class)->execute();
    expect($event->refresh()->deleted_at)->not->toBeNull()
        ->and(AuditEvent::count())->toBe(0);

    // Stage 2: prune must NOT delete a freshly archived row (grace window).
    app(PruneAuditEvents::class)->execute(retentionDays: 30);
    expect(AuditEvent::withTrashed()->count())->toBe(1);

    // Once the archive time ages past the prune cutoff, prune deletes it.
    $event->forceFill(['deleted_at' => now()->subDays(31)])->save();
    app(PruneAuditEvents::class)->execute(retentionDays: 30);
    expect(AuditEvent::withTrashed()->count())->toBe(0);
});
