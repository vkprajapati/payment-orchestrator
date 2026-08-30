<?php

use App\Actions\Merchants\CreateMerchantForUser;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates a default merchant when a user registers', function () {
    $response = $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect('/dashboard');

    $this->assertDatabaseHas('users', [
        'email' => 'john@example.com',
    ]);

    $this->assertDatabaseHas('merchants', [
        'name' => "John Doe's Workspace",
        'slug' => 'john-does-workspace',
        'status' => 'active',
    ]);

    $merchant = Merchant::where('slug', 'john-does-workspace')->firstOrFail();

    $this->assertDatabaseHas('merchant_user', [
        'merchant_id' => $merchant->id,
        'user_id' => User::where('email', 'john@example.com')->firstOrFail()->id,
        'role' => 'owner',
    ]);
});

it('assigns the registering user as the merchant owner', function () {
    $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'john@example.com')->firstOrFail();

    expect($user->merchants)->toHaveCount(1);
    expect($user->merchants->first()->name)->toBe("John Doe's Workspace");
    expect($user->merchants->first()->pivot->role)->toBe(CreateMerchantForUser::OWNER_ROLE);
});

it('names the merchant after the registering user', function () {
    $this->post('/register', [
        'name' => 'Acme Corporation',
        'email' => 'acme@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $merchant = Merchant::where('name', "Acme Corporation's Workspace")->firstOrFail();

    expect($merchant->name)->toBe("Acme Corporation's Workspace");
    expect($merchant->slug)->toBe('acme-corporations-workspace');
    expect($merchant->status)->toBe('active');
});

it('generates a URL-safe slug for the merchant', function () {
    $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $merchant = Merchant::firstOrFail();

    expect($merchant->slug)->toBe('john-does-workspace');
    expect($merchant->slug)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/');
});

it('appends a numeric suffix when merchant slugs collide', function () {
    $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    // Log the first user out so the second registration is not blocked by the guest middleware.
    $this->post('/logout');

    $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(Merchant::count())->toBe(2);

    $slugs = Merchant::pluck('slug')->sort()->values()->all();

    expect($slugs)->toBe(['john-does-workspace', 'john-does-workspace-2']);
    expect(count(array_unique($slugs)))->toBe(2);
});

it('rolls back the registration when merchant creation fails', function () {
    $this->mock(CreateMerchantForUser::class, function ($mock) {
        $mock->shouldReceive('create')
            ->once()
            ->andThrow(new RuntimeException('Merchant creation failed.'));
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(RuntimeException::class);

    $this->assertDatabaseMissing('users', [
        'email' => 'john@example.com',
    ]);

    $this->assertDatabaseCount('merchants', 0);
    $this->assertDatabaseCount('merchant_user', 0);
});
