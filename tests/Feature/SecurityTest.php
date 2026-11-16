<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ---------- Registration limit ----------

function registerAs(object $test, string $email)
{
    return $test->postJson('/api/register', [
        'name' => 'Atik',
        'email' => $email,
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);
}

it('limits registration to 5 requests per minute', function () {
    foreach (range(1, 5) as $i) {
        registerAs($this, "user{$i}@test.com")->assertCreated();
    }

    registerAs($this, 'user6@test.com')->assertStatus(429)->assertHeader('Retry-After');

    expect(User::count())->toBe(5);
});

it('allows registration again after one minute', function () {
    foreach (range(1, 6) as $i) {
        registerAs($this, "user{$i}@test.com");
    }

    $this->travel(61)->seconds();

    registerAs($this, 'later@test.com')->assertCreated();
});

it('does not share the registration limit with login', function () {
    foreach (range(1, 5) as $i) {
        registerAs($this, "user{$i}@test.com")->assertCreated();
    }

    $this->postJson('/api/login', ['email' => 'user1@test.com', 'password' => 'wrong'])->assertStatus(401);
});

// ---------- Catalog roles ----------

$catalogRoutes = [
    ['GET', '/api/brands'], ['POST', '/api/brands'], ['GET', '/api/brands/999'],
    ['PUT', '/api/brands/999'], ['DELETE', '/api/brands/999'],
    ['GET', '/api/brands/999/collections'], ['POST', '/api/brands/999/collections'],
    ['GET', '/api/collections'], ['GET', '/api/collections/999'],
    ['PUT', '/api/collections/999'], ['DELETE', '/api/collections/999'],
    ['GET', '/api/products'], ['POST', '/api/products'], ['GET', '/api/products/999'],
    ['PUT', '/api/products/999'], ['DELETE', '/api/products/999'],
    ['GET', '/api/products/999/variants'], ['POST', '/api/products/999/variants'],
    ['GET', '/api/variants/999'], ['PUT', '/api/variants/999'], ['DELETE', '/api/variants/999'],
    ['POST', '/api/products/999/featured-image'], ['DELETE', '/api/products/999/featured-image'],
    ['POST', '/api/variants/999/images'], ['DELETE', '/api/variants/999/images/1'],
];

it('rejects guests on every catalog route', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with($catalogRoutes);

it('gives customers 403 on every catalog route, also for ids that do not exist', function (string $method, string $uri) {
    Sanctum::actingAs(User::factory()->create());

    $this->json($method, $uri)
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to do this.');
})->with($catalogRoutes);

it('lets staff and admins reach the catalog', function (string $role) {
    Sanctum::actingAs(User::factory()->{$role}()->create());

    $this->getJson('/api/brands')->assertOk();
    $this->getJson('/api/products')->assertOk();
})->with(['staff', 'admin']);

it('does not let a customer tell a missing product from an existing draft', function () {
    Sanctum::actingAs(User::factory()->create());
    $draft = makeProduct(['status' => 'draft'], 0);

    $existing = $this->getJson("/api/products/{$draft->id}")->status();
    $missing = $this->getJson('/api/products/99999')->status();

    expect($existing)->toBe(403);
    expect($missing)->toBe(403);
});

// ---------- Token expiry ----------
it('stops accepting a token after it expires', function () {
    config(['sanctum.expiration' => 60]);
    $token = User::factory()->create()->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/profile')->assertOk();

    $this->travel(61)->minutes();
    $this->app['auth']->forgetGuards(); // the test app keeps the first user between requests

    $this->withToken($token)->getJson('/api/profile')->assertUnauthorized();
});

it('keeps accepting a token that has not expired yet', function () {
    config(['sanctum.expiration' => 60]);
    $token = User::factory()->create()->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/profile')->assertOk();

    $this->travel(59)->minutes();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/profile')->assertOk();
});
