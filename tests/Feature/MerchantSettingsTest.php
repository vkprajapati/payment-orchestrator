<?php

use App\Models\Merchant;
use App\Models\User;
use App\Services\Merchants\CurrentMerchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('allows an owner to view workspace settings', function () {
    $merchant = Merchant::factory()->create([
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
    ]);
    $user = User::factory()->create();
    $user->merchants()->attach($merchant, ['role' => 'owner']);

    $this->withSession([CurrentMerchant::SESSION_KEY => $merchant->id])
        ->actingAs($user)
        ->get('/settings/workspace')
        ->assertOk()
        ->assertSee('Workspace Information')
        ->assertSee('Acme Inc.')
        ->assertSee('acme-inc');
});

it('allows an owner to update workspace settings', function () {
    $merchant = Merchant::factory()->create([
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
    ]);
    $user = User::factory()->create();
    $user->merchants()->attach($merchant, ['role' => 'owner']);

    $this->withSession([CurrentMerchant::SESSION_KEY => $merchant->id])
        ->actingAs($user)
        ->put('/settings/workspace', [
            'name' => 'Acme Corporation',
            'slug' => 'acme-corporation',
        ])
        ->assertRedirect(route('settings.workspace.edit'));

    $this->assertDatabaseHas('merchants', [
        'id' => $merchant->id,
        'name' => 'Acme Corporation',
        'slug' => 'acme-corporation',
        'status' => 'active',
    ]);
});

it('allows an admin to update workspace settings', function () {
    $merchant = Merchant::factory()->create([
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
    ]);
    $user = User::factory()->create();
    $user->merchants()->attach($merchant, ['role' => 'admin']);

    $this->withSession([CurrentMerchant::SESSION_KEY => $merchant->id])
        ->actingAs($user)
        ->put('/settings/workspace', [
            'name' => 'Acme Admin Co.',
            'slug' => 'acme-admin-co',
        ])
        ->assertRedirect(route('settings.workspace.edit'));

    $this->assertDatabaseHas('merchants', [
        'id' => $merchant->id,
        'name' => 'Acme Admin Co.',
        'slug' => 'acme-admin-co',
    ]);
});

it('forbids a developer from updating workspace settings', function () {
    $merchant = Merchant::factory()->create([
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
    ]);
    $user = User::factory()->create();
    $user->merchants()->attach($merchant, ['role' => 'developer']);

    $this->withSession([CurrentMerchant::SESSION_KEY => $merchant->id])
        ->actingAs($user)
        ->put('/settings/workspace', [
            'name' => 'Hacked',
            'slug' => 'hacked',
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('merchants', [
        'id' => $merchant->id,
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
    ]);
});

it('forbids a viewer from updating workspace settings', function () {
    $merchant = Merchant::factory()->create([
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
    ]);
    $user = User::factory()->create();
    $user->merchants()->attach($merchant, ['role' => 'viewer']);

    $this->withSession([CurrentMerchant::SESSION_KEY => $merchant->id])
        ->actingAs($user)
        ->put('/settings/workspace', [
            'name' => 'Hacked',
            'slug' => 'hacked',
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('merchants', [
        'id' => $merchant->id,
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
    ]);
});

it('rejects a slug that belongs to another merchant', function () {
    $user = User::factory()->create();
    $merchantA = Merchant::factory()->create(['name' => 'Merchant A', 'slug' => 'merchant-a']);
    $merchantB = Merchant::factory()->create(['name' => 'Merchant B', 'slug' => 'merchant-b']);
    $user->merchants()->attach($merchantA, ['role' => 'owner']);

    $this->withSession([CurrentMerchant::SESSION_KEY => $merchantA->id])
        ->actingAs($user)
        ->put('/settings/workspace', [
            'name' => 'Merchant A',
            'slug' => 'merchant-b',
        ])
        ->assertSessionHasErrors('slug');

    $this->assertDatabaseHas('merchants', [
        'id' => $merchantA->id,
        'name' => 'Merchant A',
        'slug' => 'merchant-a',
    ]);
});

it('rejects invalid workspace slugs', function (string $invalidSlug) {
    $merchant = Merchant::factory()->create([
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
    ]);
    $user = User::factory()->create();
    $user->merchants()->attach($merchant, ['role' => 'owner']);

    $this->withSession([CurrentMerchant::SESSION_KEY => $merchant->id])
        ->actingAs($user)
        ->put('/settings/workspace', [
            'name' => 'Acme Inc.',
            'slug' => $invalidSlug,
        ])
        ->assertSessionHasErrors('slug');

    $this->assertDatabaseHas('merchants', [
        'id' => $merchant->id,
        'slug' => 'acme-inc',
    ]);
})->with(['My Company', 'invalid slug', 'UPPERCASE']);

it('never updates a merchant other than the current one', function () {
    $user = User::factory()->create();
    $ownedMerchant = Merchant::factory()->create(['name' => 'Owned Co.', 'slug' => 'owned-co']);
    $foreignMerchant = Merchant::factory()->create(['name' => 'Foreign Co.', 'slug' => 'foreign-co']);
    $user->merchants()->attach($ownedMerchant, ['role' => 'owner']);

    $this->withSession([CurrentMerchant::SESSION_KEY => $ownedMerchant->id])
        ->actingAs($user)
        ->put('/settings/workspace', [
            'name' => 'Owned Updated',
            'slug' => 'owned-updated',
            'merchant_id' => $foreignMerchant->id,
        ])
        ->assertRedirect(route('settings.workspace.edit'));

    // Only the current merchant was updated; the manipulated merchant_id input was ignored.
    $this->assertDatabaseHas('merchants', [
        'id' => $ownedMerchant->id,
        'name' => 'Owned Updated',
        'slug' => 'owned-updated',
    ]);

    $this->assertDatabaseHas('merchants', [
        'id' => $foreignMerchant->id,
        'name' => 'Foreign Co.',
        'slug' => 'foreign-co',
    ]);
});

it('flashes a success message after updating workspace settings', function () {
    $merchant = Merchant::factory()->create([
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
    ]);
    $user = User::factory()->create();
    $user->merchants()->attach($merchant, ['role' => 'owner']);

    $this->withSession([CurrentMerchant::SESSION_KEY => $merchant->id])
        ->actingAs($user)
        ->put('/settings/workspace', [
            'name' => 'Acme Corporation',
            'slug' => 'acme-corporation',
        ])
        ->assertRedirect(route('settings.workspace.edit'))
        ->assertSessionHas('status');

    $this->withSession([
        CurrentMerchant::SESSION_KEY => $merchant->id,
        'status' => 'Workspace settings updated successfully.',
    ])
        ->actingAs($user)
        ->get('/settings/workspace')
        ->assertOk()
        ->assertSee('Workspace settings updated successfully.');
});
