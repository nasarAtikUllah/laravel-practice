<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function throttleLoginAttempt($test, string $email, string $password)
{
    return $test->postJson('/api/login', [
        'email' => $email,
        'password' => $password,
    ]);
}

it('can login successfully', function () {

    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $response
        ->assertOk()
        ->assertJsonStructure([
            'user',
            'token',
        ]);
});
it('rejects wrong password', function () {

    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong',
    ]);

    $response->assertStatus(401);
});

it('rejects unknown email', function () {

    $response = $this->postJson('/api/login', [
        'email' => 'missing@test.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(401);
});

it('requires email', function () {

    $response = $this->postJson('/api/login', [
        'password' => 'password123',
    ]);

    $response->assertStatus(422);
});

it('requires password', function () {

    $response = $this->postJson('/api/login', [
        'email' => 'test@test.com',
    ]);

    $response->assertStatus(422);
});

it('requires valid email format', function () {

    $response = $this->postJson('/api/login', [
        'email' => 'invalid-email',
        'password' => 'password123',
    ]);

    $response->assertStatus(422);
});

it('rejects inactive user', function () {

    $user = User::factory()->create([
        'is_active' => false,
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $response->assertStatus(403);
});

it('returns token after login', function () {

    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password123',
    ]);

    $response->assertJsonStructure([
        'token',
    ]);
});

it('allows 5 failed login attempts and blocks the 6th', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);

    foreach (range(1, 5) as $i) {
        throttleLoginAttempt($this, $user->email, 'wrong-password')
            ->assertStatus(401);
    }

    throttleLoginAttempt($this, $user->email, 'wrong-password')
        ->assertStatus(429);
});

it('blocks the correct password too once the limit is reached', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);

    foreach (range(1, 5) as $i) {
        throttleLoginAttempt($this, $user->email, 'wrong-password');
    }

    throttleLoginAttempt($this, $user->email, 'password123')
        ->assertStatus(429);
});

it('still logs in on the 5th attempt if the password is correct', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);

    foreach (range(1, 4) as $i) {
        throttleLoginAttempt($this, $user->email, 'wrong-password')
            ->assertStatus(401);
    }

    throttleLoginAttempt($this, $user->email, 'password123')
        ->assertOk()
        ->assertJsonStructure(['user', 'token']);
});

it('returns a Retry-After header when throttled', function () {
    foreach (range(1, 5) as $i) {
        throttleLoginAttempt($this, 'nobody@test.com', 'wrong-password');
    }

    throttleLoginAttempt($this, 'nobody@test.com', 'wrong-password')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('shows the remaining attempts in the rate limit header', function () {
    $response = throttleLoginAttempt($this, 'nobody@test.com', 'wrong-password');

    $response->assertHeader('X-RateLimit-Limit', 5)
        ->assertHeader('X-RateLimit-Remaining', 4);
});

it('allows login again after the throttle window passes', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);

    foreach (range(1, 6) as $i) {
        throttleLoginAttempt($this, $user->email, 'wrong-password');
    }

    throttleLoginAttempt($this, $user->email, 'password123')->assertStatus(429);

    $this->travel(61)->seconds();

    throttleLoginAttempt($this, $user->email, 'password123')->assertOk();
});
