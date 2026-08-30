<?php

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates a merchant with the expected default values', function () {
    $merchant = Merchant::factory()->create([
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
    ]);

    $this->assertDatabaseHas('merchants', [
        'id' => $merchant->id,
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
        'status' => 'active',
    ]);

    expect($merchant->status)->toBe('active');
    expect($merchant->metadata)->toBe([]);
});

it('generates unique slugs across multiple merchants', function () {
    $first = Merchant::factory()->create();
    $second = Merchant::factory()->create();
    $third = Merchant::factory()->create();

    $slugs = [$first->slug, $second->slug, $third->slug];

    expect($slugs)->toHaveCount(3);
    expect(count(array_unique($slugs)))->toBe(3);
});

it('enforces the unique slug constraint', function () {
    Merchant::factory()->create(['slug' => 'acme-inc']);

    $this->expectException(QueryException::class);

    Merchant::factory()->create(['slug' => 'acme-inc']);
});

it('has users via the pivot table', function () {
    $merchant = Merchant::factory()->create();
    $user = User::factory()->create();

    $merchant->users()->attach($user, ['role' => 'owner']);

    expect($merchant->users)->toHaveCount(1);
    expect($merchant->users->first()->id)->toBe($user->id);
});

it('allows a user to belong to multiple merchants', function () {
    $user = User::factory()->create();
    $firstMerchant = Merchant::factory()->create();
    $secondMerchant = Merchant::factory()->create();

    $user->merchants()->attach($firstMerchant, ['role' => 'viewer']);
    $user->merchants()->attach($secondMerchant, ['role' => 'developer']);

    expect($user->merchants)->toHaveCount(2);
    expect($user->merchants->pluck('id'))
        ->toContain($firstMerchant->id, $secondMerchant->id);
});

it('stores and exposes the pivot role', function () {
    $merchant = Merchant::factory()->create();
    $user = User::factory()->create();

    $merchant->users()->attach($user, ['role' => 'owner']);

    $this->assertDatabaseHas('merchant_user', [
        'merchant_id' => $merchant->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    expect($merchant->users->first()->pivot->role)->toBe('owner');
});

it('prevents duplicate membership through the unique constraint', function () {
    $merchant = Merchant::factory()->create();
    $user = User::factory()->create();

    $merchant->users()->attach($user, ['role' => 'viewer']);

    $this->expectException(QueryException::class);

    $merchant->users()->attach($user, ['role' => 'admin']);
});

it('removes pivot rows when a merchant is deleted, but keeps the user', function () {
    $merchant = Merchant::factory()->create();
    $user = User::factory()->create();

    $merchant->users()->attach($user, ['role' => 'viewer']);

    $merchant->delete();

    $this->assertDatabaseMissing('merchant_user', [
        'merchant_id' => $merchant->id,
        'user_id' => $user->id,
    ]);

    expect(User::find($user->id))->not->toBeNull();
});

it('removes pivot rows when a user is deleted, but keeps the merchant', function () {
    $merchant = Merchant::factory()->create();
    $user = User::factory()->create();

    $merchant->users()->attach($user, ['role' => 'viewer']);

    $user->delete();

    $this->assertDatabaseMissing('merchant_user', [
        'merchant_id' => $merchant->id,
        'user_id' => $user->id,
    ]);

    expect(Merchant::find($merchant->id))->not->toBeNull();
});
