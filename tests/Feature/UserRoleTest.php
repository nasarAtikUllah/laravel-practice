<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('gives a new user the customer role', function () {
    $this->postJson('/api/register', [
        'name' => 'Atik',
        'email' => 'atik@test.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    expect(User::firstOrFail()->role)->toBe(UserRole::Customer);
});

it('ignores a role sent at registration', function () {
    $this->postJson('/api/register', [
        'name' => 'Atik',
        'email' => 'atik@test.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'admin',
    ])->assertCreated();

    expect(User::firstOrFail()->role)->toBe(UserRole::Customer);
});

it('lets only admins manage inventory', function () {
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->create();

    expect($admin->isAdmin())->toBeTrue();
    expect($customer->isAdmin())->toBeFalse();
    expect(Gate::forUser($admin)->allows('manage-inventory'))->toBeTrue();
    expect(Gate::forUser($customer)->allows('manage-inventory'))->toBeFalse();
});

it('lets admins and staff manage inventory but not customers', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->create();

    expect(Gate::forUser($admin)->allows('manage-inventory'))->toBeTrue();
    expect(Gate::forUser($staff)->allows('manage-inventory'))->toBeTrue();
    expect(Gate::forUser($customer)->allows('manage-inventory'))->toBeFalse();
});

it('does not make staff an admin', function () {
    $staff = User::factory()->staff()->create();

    expect($staff->isAdmin())->toBeFalse();
    expect($staff->canManageInventory())->toBeTrue();
});
it('knows who is an admin', function () {
    expect(User::factory()->admin()->create()->isAdmin())->toBeTrue();
    expect(User::factory()->staff()->create()->isAdmin())->toBeFalse();
    expect(User::factory()->create()->isAdmin())->toBeFalse();
});
