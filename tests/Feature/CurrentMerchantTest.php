<?php

use App\Models\Merchant;
use App\Models\User;
use App\Services\Merchants\CurrentMerchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('automatically selects the first merchant when none is set', function () {
    $user = User::factory()->create();
    $first = Merchant::factory()->create();
    $second = Merchant::factory()->create();

    $user->merchants()->attach($first, ['role' => 'owner', 'created_at' => now()->subDays(2)]);
    $user->merchants()->attach($second, ['role' => 'viewer', 'created_at' => now()->subDay()]);

    $this->actingAs($user)->get('/dashboard')->assertOk();

    expect(session(CurrentMerchant::SESSION_KEY))->toBe($first->id);
    expect(app(CurrentMerchant::class)->get()->is($first))->toBeTrue();
});

it('uses the merchant already selected in the session', function () {
    $user = User::factory()->create();
    $first = Merchant::factory()->create();
    $second = Merchant::factory()->create();

    $user->merchants()->attach($first, ['role' => 'owner']);
    $user->merchants()->attach($second, ['role' => 'viewer']);

    $this->withSession([CurrentMerchant::SESSION_KEY => $second->id])
        ->actingAs($user)
        ->get('/dashboard')
        ->assertOk();

    expect(session(CurrentMerchant::SESSION_KEY))->toBe($second->id);
    expect(app(CurrentMerchant::class)->get()->is($second))->toBeTrue();
});

it('rejects a merchant the user does not belong to', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $ownMerchant = Merchant::factory()->create();
    $foreignMerchant = Merchant::factory()->create();

    $user->merchants()->attach($ownMerchant, ['role' => 'owner']);
    $otherUser->merchants()->attach($foreignMerchant, ['role' => 'owner']);

    $this->withSession([CurrentMerchant::SESSION_KEY => $foreignMerchant->id])
        ->actingAs($user)
        ->get('/dashboard')
        ->assertOk();

    // The foreign merchant is never resolved as the current merchant.
    expect(app(CurrentMerchant::class)->id())->not->toBe($foreignMerchant->id);
    expect(app(CurrentMerchant::class)->get()->is($ownMerchant))->toBeTrue();

    // The invalid session context is replaced with a valid fallback.
    expect(session(CurrentMerchant::SESSION_KEY))->toBe($ownMerchant->id);
});

it('renders an empty state for a user without merchants', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('No workspace available');

    expect(session(CurrentMerchant::SESSION_KEY))->toBeNull();
    expect(app(CurrentMerchant::class)->has())->toBeFalse();
});

it('displays the current merchant name and slug on the dashboard', function () {
    $user = User::factory()->create();
    $merchant = Merchant::factory()->create([
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
    ]);

    $user->merchants()->attach($merchant, ['role' => 'owner']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Acme Inc.')
        ->assertSee('acme-inc');
});

it('exposes the current user role within the merchant context', function () {
    $user = User::factory()->create();
    $merchant = Merchant::factory()->create();

    $user->merchants()->attach($merchant, ['role' => 'owner']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Owner');

    expect(app(CurrentMerchant::class)->role())->toBe('owner');
});
