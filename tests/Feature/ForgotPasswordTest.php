<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

function resetPayload(User $user, string $token, array $overrides = []): array
{
    return array_merge([
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword123',
        'password_confirmation' => 'NewPassword123',
    ], $overrides);
}

it('resets the password with a valid token', function () {
    Event::fake([PasswordReset::class]);

    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->postJson('/api/reset-password', resetPayload($user, $token))
        ->assertOk()
        ->assertJson(['message' => 'Password reset successful']);

    // Catches the double-hashing bug if your User model casts password as 'hashed'
    expect(Hash::check('NewPassword123', $user->fresh()->password))->toBeTrue();

    Event::assertDispatched(PasswordReset::class);
});

it('revokes all api tokens after a reset', function () {
    $user = User::factory()->create();
    $user->createToken('phone');
    $user->createToken('laptop');
    $token = Password::createToken($user);

    $this->postJson('/api/reset-password', resetPayload($user, $token))->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('rejects an invalid token', function () {
    $user = User::factory()->create();

    $this->postJson('/api/reset-password', resetPayload($user, 'wrong-token'))
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid or expired token']);
});

it('rejects an expired token', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->travel(61)->minutes(); // default expiry is 60 minutes

    $this->postJson('/api/reset-password', resetPayload($user, $token))
        ->assertStatus(400);
});

it('does not allow a token to be used twice', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->postJson('/api/reset-password', resetPayload($user, $token))->assertOk();

    $this->postJson('/api/reset-password', resetPayload($user, $token, [
        'password' => 'AnotherPass456',
        'password_confirmation' => 'AnotherPass456',
    ]))->assertStatus(400);
});
it('returns the same error for an unknown email as for a bad token', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->postJson('/api/reset-password', resetPayload($user, $token, [
        'email' => 'nobody@example.com',
    ]))
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid or expired token']);
});

it('requires a matching password confirmation', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->postJson('/api/reset-password', resetPayload($user, $token, [
        'password_confirmation' => 'Different123',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('rejects a password that is too short', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->postJson('/api/reset-password', resetPayload($user, $token, [
        'password' => 'short',
        'password_confirmation' => 'short',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('requires token and email', function () {
    $this->postJson('/api/reset-password', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['token', 'email', 'password']);
});

it('sends a reset link to a registered email', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/api/forgot-password', ['email' => $user->email])
        ->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('stores a reset token for the user', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

    $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
});

it('requires an email', function () {
    $this->postJson('/api/forgot-password', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('requires a valid email format', function () {
    $this->postJson('/api/forgot-password', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('does not send anything for an unknown email', function () {
    Notification::fake();

    $this->postJson('/api/forgot-password', ['email' => 'nobody@test.com']);

    Notification::assertNothingSent();
});

it('gives the same response for known and unknown emails', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->postJson('/api/forgot-password', ['email' => $user->email]);
    $unknown = $this->postJson('/api/forgot-password', ['email' => 'nobody@test.com']);

    expect($unknown->status())->toBe($known->status());
    expect($unknown->json('message'))->toBe($known->json('message'));
});

it('throttles forgot-password requests after 5 attempts', function () {
    Notification::fake();

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/forgot-password', ['email' => 'nobody@test.com']);
    }

    $this->postJson('/api/forgot-password', ['email' => 'nobody@test.com'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('never reveals whether an email exists', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->postJson('/api/forgot-password', ['email' => $user->email]);
    $unknown = $this->postJson('/api/forgot-password', ['email' => 'nobody@test.com']);

    $known->assertOk();
    $unknown->assertOk();
    expect($unknown->json())->toBe($known->json());
});

it('gives the same answer when a known email is requested twice quickly', function () {
    Notification::fake();
    $user = User::factory()->create();

    $first = $this->postJson('/api/forgot-password', ['email' => $user->email]);
    $second = $this->postJson('/api/forgot-password', ['email' => $user->email]);

    expect($second->status())->toBe($first->status());
    expect($second->json())->toBe($first->json());
});
