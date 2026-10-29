<?php

// /tests/Feature/Auth/RegisterTest.php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can register a user', function () {

    $response = $this->postJson('/api/register', [
        'name' => 'Atik',
        'email' => 'atik@test.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated();
});

it('requires name', function () {

    $response = $this->postJson('/api/register', [
        'email' => 'atik@test.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('requires email', function () {

    $response = $this->postJson('/api/register', [
        'name' => 'Atik',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('requires password', function () {

    $response = $this->postJson('/api/register', [
        'name' => 'Atik',
        'email' => 'atik@test.com',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('requires valid email', function () {

    $response = $this->postJson('/api/register', [
        'name' => 'Atik',
        'email' => 'invalid-email',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('prevents duplicate email', function () {

    User::factory()->create([
        'email' => 'atik@test.com',
    ]);

    $response = $this->postJson('/api/register', [
        'name' => 'Atik',
        'email' => 'atik@test.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('requires minimum password length', function () {

    $response = $this->postJson('/api/register', [
        'name' => 'Atik',
        'email' => 'atik@test.com',
        'password' => '123',
        'password_confirmation' => '123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('requires password confirmation match', function () {

    $response = $this->postJson('/api/register', [
        'name' => 'Atik',
        'email' => 'atik@test.com',
        'password' => 'password123',
        'password_confirmation' => 'wrongpassword',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('rejects long name', function () {

    $response = $this->postJson('/api/register', [
        'name' => str_repeat('a', 256),
        'email' => 'atik@test.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('returns token after registration', function () {

    $response = $this->postJson('/api/register', [
        'name' => 'Atik',
        'email' => 'atik@test.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'user',
            'token',
        ]);
});
