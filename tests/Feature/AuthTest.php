<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('displays the login page', function () {
    $this->get('/login')->assertOk();
});

it('displays the registration page', function () {
    $this->get('/register')->assertOk();
});

it('allows a user to register', function () {
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
});

it('allows a user to log in', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/dashboard');

    $this->assertAuthenticated();
});

it('allows a user to log out', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response = $this->post('/logout');

    $response->assertRedirect('/login');

    $this->assertGuest();
});

it('prevents a guest from accessing the dashboard', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('allows an authenticated user to access the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get('/dashboard')->assertOk();
});
