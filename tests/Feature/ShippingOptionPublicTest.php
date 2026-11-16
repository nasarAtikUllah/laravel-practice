<?php

use App\Models\ShippingOption;
use Database\Seeders\ShippingOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------- Public list ----------

it('lets guests list the delivery options in BDT', function () {
    seedShipping();

    $this->getJson('/api/frontend/shipping-options')
        ->assertOk()
        ->assertJsonPath('message', 'Delivery options retrieved successfully.')
        ->assertJsonPath('currency', 'BDT')
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.code', 'inside-city')
        ->assertJsonPath('data.0.fee', '60.00')
        ->assertJsonPath('data.1.code', 'outside-city')
        ->assertJsonPath('data.1.fee', '120.00')
        ->assertJsonPath('data.2.code', 'free-delivery')
        ->assertJsonPath('data.2.fee', '0.00')
        ->assertJsonPath('data.2.min_order_amount', '5000.00');
});

it('hides inactive and deleted options', function () {
    ShippingOption::factory()->asDefault()->create(['code' => 'visible']);
    ShippingOption::factory()->inactive()->create(['code' => 'off']);
    ShippingOption::factory()->create(['code' => 'gone'])->delete();

    $this->getJson('/api/frontend/shipping-options')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'visible');
});

it('does not show private fields of a delivery option', function () {
    ShippingOption::factory()->asDefault()->create();

    $this->getJson('/api/frontend/shipping-options')
        ->assertOk()
        ->assertJsonMissingPath('data.0.is_default')
        ->assertJsonMissingPath('data.0.is_active')
        ->assertJsonMissingPath('data.0.sort_order')
        ->assertJsonMissingPath('data.0.created_at');
});

// ---------- Seeder ----------

it('seeds the three delivery options', function () {
    $this->seed(ShippingOptionSeeder::class);

    expect(ShippingOption::count())->toBe(3);

    $inside = ShippingOption::where('code', 'inside-city')->firstOrFail();
    $outside = ShippingOption::where('code', 'outside-city')->firstOrFail();
    $free = ShippingOption::where('code', 'free-delivery')->firstOrFail();

    expect($inside->fee)->toBe('60.00');
    expect($inside->is_default)->toBeTrue();
    expect($outside->fee)->toBe('120.00');
    expect($outside->is_default)->toBeFalse();
    expect($free->fee)->toBe('0.00');
    expect($free->min_order_amount)->toBe('5000.00');
    expect(ShippingOption::where('is_default', true)->count())->toBe(1);
});

it('takes the free delivery minimum from the config', function () {
    config(['shop.free_delivery_min_order' => 3000]);

    $this->seed(ShippingOptionSeeder::class);

    expect(ShippingOption::where('code', 'free-delivery')->firstOrFail()->min_order_amount)->toBe('3000.00');
});

it('does not duplicate the options when the seeder runs twice', function () {
    $this->seed(ShippingOptionSeeder::class);
    $this->seed(ShippingOptionSeeder::class);

    expect(ShippingOption::count())->toBe(3);
});

it('does not overwrite a fee the admin changed', function () {
    $this->seed(ShippingOptionSeeder::class);
    ShippingOption::where('code', 'inside-city')->update(['fee' => 70]);

    $this->seed(ShippingOptionSeeder::class);

    expect(ShippingOption::where('code', 'inside-city')->firstOrFail()->fee)->toBe('70.00');
});

it('does not create an option the admin deleted', function () {
    $this->seed(ShippingOptionSeeder::class);
    ShippingOption::where('code', 'free-delivery')->firstOrFail()->delete();

    $this->seed(ShippingOptionSeeder::class);

    expect(ShippingOption::count())->toBe(2);
    expect(ShippingOption::withTrashed()->count())->toBe(3);
});
