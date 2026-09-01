<?php

use App\Exceptions\Api\ApiClientException;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Api\ApiClient;
use App\Services\Merchants\CurrentMerchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Reusable Blade components
// ---------------------------------------------------------------------------

it('renders the alert component with a type-specific style', function () {
    $html = Blade::render('<x-alert type="success">Saved.</x-alert>');

    expect($html)->toContain('Saved.')
        ->toContain('alert-success')
        ->toContain('role="alert"');
});

it('renders the alert with an optional title', function () {
    $html = Blade::render('<x-alert type="error" title="Operation failed">Please retry.</x-alert>');

    expect($html)->toContain('Operation failed')->toContain('alert-danger');
});

it('renders the alert dismissible', function () {
    $html = Blade::render('<x-alert type="warning" dismissible>Heads up.</x-alert>');

    expect($html)->toContain('alert-dismissible')->toContain('btn-close');
});

it('renders the badge with the requested variant', function () {
    $html = Blade::render('<x-badge variant="active">Active</x-badge>');

    expect($html)->toContain('Active')->toContain('badge-status-active');
});

it('renders the badge for revoked / suspended states', function () {
    $html = Blade::render('<x-badge variant="revoked">Revoked</x-badge>');

    expect($html)->toContain('Revoked')->toContain('badge-status-suspended');
});

it('renders the empty state with title, message and slot', function () {
    $html = Blade::render(
        '<x-empty-state title="No payments yet" message="Create one to get started." icon="📭"><a href="/payments/create">Create</a></x-empty-state>',
    );

    expect($html)->toContain('No payments yet')
        ->toContain('Create one to get started.')
        ->toContain('/payments/create');
});

it('renders the loading indicator with a label', function () {
    $html = Blade::render('<x-loading label="Creating…" />');

    expect($html)->toContain('spinner-border')
        ->toContain('Creating…')
        ->toContain('visually-hidden');
});

it('renders the pagination component from a live paginator', function () {
    $paginator = new LengthAwarePaginator(
        items: new Collection([['id' => 1]]),
        total: 25,
        perPage: 10,
        currentPage: 1,
        options: ['path' => '/payments'],
    );

    $html = Blade::render('<x-pagination :paginator="$paginator" />', ['paginator' => $paginator]);

    expect($html)->toContain('Showing 1')
        ->toContain('of 25')
        ->toContain('pagination');
});

it('renders a confirm form that spoofs DELETE and carries data-confirm', function () {
    $html = Blade::render(
        '<x-confirm action="/settings/api-keys/1" method="DELETE" message="Revoke this key?"><button>Revoke</button></x-confirm>',
    );

    expect($html)->toContain('data-confirm="Revoke this key?')
        ->toContain('name="_method"')
        ->toContain('value="DELETE"')
        ->toContain('Revoke');
});

// ---------------------------------------------------------------------------
// API client foundation
// ---------------------------------------------------------------------------

it('performs an authenticated GET and preserves the pagination envelope', function () {
    Http::fake(['*' => Http::response(['data' => ['a' => 1], 'meta' => ['current_page' => 2]], 200)]);

    $client = new ApiClient('https://example.test', 'sk_test_'.str_repeat('a', 40));
    $payload = $client->get('/api/v1/payments', ['per_page' => 20]);

    expect($payload['data'])->toBe(['a' => 1])
        ->and($payload['meta']['current_page'])->toBe(2);

    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('Authorization', 'Bearer sk_test_'.str_repeat('a', 40))
            && str_contains($request->url(), 'per_page=20');
    });
});

it('sends an Idempotency-Key header on POST', function () {
    Http::fake(['*' => Http::response(['data' => ['reference' => 'pay_123']], 201)]);

    $client = new ApiClient('https://example.test', 'sk_test_'.str_repeat('a', 40));
    $client->post('/api/v1/payments', ['amount' => 100], 'idem-key-1');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'idem-key-1'));
});

it('maps a 401 response to a user-safe authentication message', function () {
    Http::fake(['*' => Http::response(['message' => 'Invalid API key.'], 401)]);

    $client = new ApiClient('https://example.test', 'sk_test_'.str_repeat('a', 40));

    try {
        $client->get('/api/v1/me');
        $this->fail('Expected ApiClientException.');
    } catch (ApiClientException $e) {
        expect($e->status)->toBe(401)
            ->and($e->getMessage())->toContain('API key')
            ->and($e->getMessage())->not->toContain('Invalid API key');
    }
});

it('maps a 403 response to a generic forbidden message', function () {
    Http::fake(['*' => Http::response(['message' => 'Forbidden.'], 403)]);

    $client = new ApiClient('https://example.test', 'sk_test_'.str_repeat('a', 40));

    try {
        $client->get('/api/v1/payments');
        $this->fail('Expected ApiClientException.');
    } catch (ApiClientException $e) {
        expect($e->status)->toBe(403)
            ->and($e->getMessage())->toContain('permission');
    }
});

it('maps a 404 response to a not-found message', function () {
    Http::fake(['*' => Http::response(['message' => 'Not found.'], 404)]);

    $client = new ApiClient('https://example.test', 'sk_test_'.str_repeat('a', 40));

    try {
        $client->get('/api/v1/payments/nope');
        $this->fail('Expected ApiClientException.');
    } catch (ApiClientException $e) {
        expect($e->status)->toBe(404)
            ->and($e->getMessage())->toContain('not found');
    }
});

it('maps a 409 idempotency conflict to a controlled message', function () {
    Http::fake(['*' => Http::response(['message' => 'Conflict.'], 409)]);

    $client = new ApiClient('https://example.test', 'sk_test_'.str_repeat('a', 40));

    try {
        $client->post('/api/v1/payments', ['amount' => 100]);
        $this->fail('Expected ApiClientException.');
    } catch (ApiClientException $e) {
        expect($e->status)->toBe(409)
            ->and($e->getMessage())->toContain('Idempotency-Key');
    }
});

it('maps a 422 response and surfaces validation errors for form feedback', function () {
    Http::fake(['*' => Http::response([
        'message' => 'The given data was invalid.',
        'errors' => ['amount' => ['The amount field is required.']],
    ], 422)]);

    $client = new ApiClient('https://example.test', 'sk_test_'.str_repeat('a', 40));

    try {
        $client->post('/api/v1/payments', []);
        $this->fail('Expected ApiClientException.');
    } catch (ApiClientException $e) {
        expect($e->status)->toBe(422)
            ->and($e->validationErrors)->toHaveKey('amount')
            ->and($e->getMessage())->toBe('The given data was invalid.');
    }
});

it('maps a 429 rate-limit response to a slow-down message', function () {
    Http::fake(['*' => Http::response(['message' => 'Too Many Requests'], 429)]);

    $client = new ApiClient('https://example.test', 'sk_test_'.str_repeat('a', 40));

    try {
        $client->get('/api/v1/payments');
        $this->fail('Expected ApiClientException.');
    } catch (ApiClientException $e) {
        expect($e->status)->toBe(429)
            ->and($e->getMessage())->toContain('Too many requests');
    }
});

it('maps a 5xx failure without exposing the raw payload', function () {
    Http::fake(['*' => Http::response(['message' => 'BOOM was attempt to trigger a stack trace'], 503)]);

    $client = new ApiClient('https://example.test', 'sk_test_'.str_repeat('a', 40));

    try {
        $client->get('/api/v1/payments');
        $this->fail('Expected ApiClientException.');
    } catch (ApiClientException $e) {
        expect($e->status)->toBe(503)
            ->and($e->getMessage())->not->toContain('BOOM')
            ->and($e->getMessage())->toContain('temporarily unavailable');
    }
});

it('maps a transport-level failure to a safe unreachable message', function () {
    Http::fake(['*' => function (): never {
        throw new ConnectionException('Connection refused');
    }]);

    $client = new ApiClient('https://example.test', 'sk_test_'.str_repeat('a', 40));

    try {
        $client->get('/api/v1/payments');
        $this->fail('Expected ApiClientException.');
    } catch (ApiClientException $e) {
        expect($e->status)->toBe(0)
            ->and($e->getMessage())->toBe('Unable to reach the API. Please try again later.')
            ->and($e->getMessage())->not->toContain('Connection refused');
    }
});

// ---------------------------------------------------------------------------
// Application shell
// ---------------------------------------------------------------------------

it('renders the application shell with navigation and the current merchant', function () {
    $merchant = Merchant::factory()->create(['name' => 'Acme Inc']);
    $user = User::factory()->create();
    $user->merchants()->attach($merchant, ['role' => 'owner']);

    $this->withSession([CurrentMerchant::SESSION_KEY => $merchant->id])
        ->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Payment Orchestrator')
        ->assertSee('Dashboard')
        ->assertSee('Workspace Settings')
        ->assertSee('API Keys')
        ->assertSee($merchant->name);
});

it('renders the dashboard empty state when no merchant is available', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('No workspace available');
});
