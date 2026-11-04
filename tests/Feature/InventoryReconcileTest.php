<?php

use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports no problems when everything matches', function () {
    $inventory = app(InventoryService::class);
    $variant = ProductVariant::factory()->create(['stock' => 0]);
    $inventory->initial($variant, 10);
    $inventory->reserve($variant, 3, 'order', 1);

    $this->artisan('inventory:reconcile')
        ->expectsOutput('No problems found.')
        ->assertSuccessful();
});

it('finds a stock that does not match the ledger', function () {
    ProductVariant::factory()->create(['stock' => 10]); // no movement was written

    $this->artisan('inventory:reconcile')
        ->expectsOutputToContain('1 variant(s) where stock does not match the ledger.')
        ->assertFailed();
});

it('finds a reserved number that does not match the active reservations', function () {
    $variant = ProductVariant::factory()->create(['stock' => 0]);
    app(InventoryService::class)->initial($variant, 10);
    $variant->forceFill(['reserved' => 4])->save();

    $this->artisan('inventory:reconcile')
        ->expectsOutputToContain('1 variant(s) where reserved does not match the active reservations.')
        ->assertFailed();
});

it('ignores reservations that were released', function () {
    $inventory = app(InventoryService::class);
    $variant = ProductVariant::factory()->create(['stock' => 0]);
    $inventory->initial($variant, 10);
    $reservation = $inventory->reserve($variant, 3, 'order', 1);
    $inventory->releaseReservation($reservation);

    $this->artisan('inventory:reconcile')->assertSuccessful();
});

it('warns about reservations that should have expired long ago', function () {
    $inventory = app(InventoryService::class);
    $variant = ProductVariant::factory()->create(['stock' => 0]);
    $inventory->initial($variant, 10);
    $inventory->reserve($variant, 3, 'order', 1);

    $this->travel(21)->minutes(); // 15 minute hold + more than 5 minutes late

    $this->artisan('inventory:reconcile')
        ->expectsOutputToContain('1 reservation(s) expired more than 5 minutes ago')
        ->assertFailed();
});

it('does not warn about a reservation that is only just expired', function () {
    $inventory = app(InventoryService::class);
    $variant = ProductVariant::factory()->create(['stock' => 0]);
    $inventory->initial($variant, 10);
    $inventory->reserve($variant, 3, 'order', 1);

    $this->travel(17)->minutes();

    $this->artisan('inventory:reconcile')->assertSuccessful();
});

it('checks deleted variants too', function () {
    ProductVariant::factory()->create(['stock' => 10])->delete();

    $this->artisan('inventory:reconcile')->assertFailed();
});

it('is clean when the release job has run', function () {
    $inventory = app(InventoryService::class);
    $variant = ProductVariant::factory()->create(['stock' => 0]);
    $inventory->initial($variant, 10);
    $inventory->reserve($variant, 3, 'order', 1);

    $this->travel(21)->minutes();
    $this->artisan('inventory:release-expired')->assertSuccessful();

    $this->artisan('inventory:reconcile')->assertSuccessful();
});
