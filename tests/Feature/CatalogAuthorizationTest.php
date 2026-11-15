<?php

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('blocks customers from managing the catalog', function () {
    Sanctum::actingAs(User::factory()->create()); // customer
    $brand = Brand::factory()->create();

    $this->getJson('/api/brands')->assertForbidden();
    $this->postJson('/api/brands', ['name' => 'Nike'])->assertForbidden();
    $this->putJson("/api/brands/{$brand->id}", ['name' => 'X'])->assertForbidden();
    $this->deleteJson("/api/brands/{$brand->id}")->assertForbidden();
    $this->getJson('/api/products')->assertForbidden();
    $this->postJson('/api/products', ['name' => 'Hack'])->assertForbidden();
});

it('lets staff manage the catalog', function () {
    Sanctum::actingAs(User::factory()->staff()->create());

    $this->getJson('/api/brands')->assertOk();
});

it('still lets guests browse the public storefront', function () {
    $this->getJson('/api/frontend/products')->assertOk();
    $this->getJson('/api/frontend/brands')->assertOk();
});
