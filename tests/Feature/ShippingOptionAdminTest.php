<?php

use App\Models\ShippingOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function shippingPayload(array $overrides = []): array
{
    return array_merge(['code' => 'inside-city', 'name' => 'Inside city delivery', 'fee' => 60], $overrides);
}

it('rejects guests on every shipping option route', function () {
    $option = ShippingOption::factory()->create();

    $this->getJson('/api/shipping-options')->assertUnauthorized();
    $this->postJson('/api/shipping-options', [])->assertUnauthorized();
    $this->getJson("/api/shipping-options/{$option->id}")->assertUnauthorized();
    $this->putJson("/api/shipping-options/{$option->id}", [])->assertUnauthorized();
    $this->deleteJson("/api/shipping-options/{$option->id}")->assertUnauthorized();
});

it('rejects customers and staff on every shipping option route', function (string $role) {
    Sanctum::actingAs($role === 'customer' ? User::factory()->create() : User::factory()->staff()->create());
    $option = ShippingOption::factory()->create();

    $this->getJson('/api/shipping-options')->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to do this.');
    $this->postJson('/api/shipping-options', shippingPayload())->assertForbidden();
    $this->getJson("/api/shipping-options/{$option->id}")->assertForbidden();
    $this->putJson("/api/shipping-options/{$option->id}", ['fee' => 10])->assertForbidden();
    $this->deleteJson("/api/shipping-options/{$option->id}")->assertForbidden();

    expect($option->fresh()->fee)->toBe('60.00');
})->with(['customer', 'staff']);

describe('when admin', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->admin()->create());
    });

    // ---------- Create ----------

    it('creates a delivery option with the defaults', function () {
        $this->postJson('/api/shipping-options', shippingPayload())
            ->assertCreated()
            ->assertJsonPath('message', 'Shipping option created successfully.')
            ->assertJsonPath('data.code', 'inside-city')
            ->assertJsonPath('data.fee', '60.00')
            ->assertJsonPath('data.min_order_amount', null)
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.sort_order', 0);

        $this->assertDatabaseHas('shipping_options', ['code' => 'inside-city']);
    });

    it('creates an option with every field', function () {
        $this->postJson('/api/shipping-options', shippingPayload([
            'code' => 'free-delivery',
            'description' => 'Free on big orders',
            'estimated_days' => '3-5 days',
            'fee' => 0,
            'min_order_amount' => 5000,
            'is_active' => false,
            'sort_order' => 30,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.fee', '0.00')
            ->assertJsonPath('data.min_order_amount', '5000.00')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.sort_order', 30)
            ->assertJsonPath('data.estimated_days', '3-5 days');
    });

    it('trims the code and saves it in small letters', function () {
        $this->postJson('/api/shipping-options', shippingPayload(['code' => '  Inside-City ']))
            ->assertCreated()
            ->assertJsonPath('data.code', 'inside-city');
    });

    it('makes the new option the only default', function () {
        $old = ShippingOption::factory()->asDefault()->create();

        $this->postJson('/api/shipping-options', shippingPayload(['is_default' => true]))
            ->assertCreated()
            ->assertJsonPath('data.is_default', true);

        expect($old->fresh()->is_default)->toBeFalse();
        expect(ShippingOption::where('is_default', true)->count())->toBe(1);
    });

    it('requires code, name and fee', function () {
        $this->postJson('/api/shipping-options', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'name', 'fee']);
    });

    it('rejects an invalid code', function (string $code) {
        $this->postJson('/api/shipping-options', shippingPayload(['code' => $code]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    })->with(['ab', 'has space', '-bad', 'bad$code', 'a-code-that-is-longer-than-forty-characters-for-sure']);

    it('rejects a duplicate code, also after the option was deleted', function () {
        $option = ShippingOption::factory()->create(['code' => 'inside-city']);

        $this->postJson('/api/shipping-options', shippingPayload())
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $option->delete();

        $this->postJson('/api/shipping-options', shippingPayload())
            ->assertStatus(422)->assertJsonValidationErrors('code');
    });

    it('rejects an invalid fee', function (mixed $fee) {
        $this->postJson('/api/shipping-options', shippingPayload(['fee' => $fee]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fee');
    })->with([-1, 'abc', 10.555, 100000000]);

    it('accepts a fee of zero', function () {
        $this->postJson('/api/shipping-options', shippingPayload(['fee' => 0]))->assertCreated();
    });

    it('rejects an invalid minimum order amount', function (mixed $minimum) {
        $this->postJson('/api/shipping-options', shippingPayload(['min_order_amount' => $minimum]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_order_amount');
    })->with([0, -5, 'abc']);

    it('does not allow a minimum order on the default option', function () {
        $this->postJson('/api/shipping-options', shippingPayload(['is_default' => true, 'min_order_amount' => 500]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_order_amount');

        expect(ShippingOption::count())->toBe(0);
    });

    it('does not allow an inactive default option', function () {
        $this->postJson('/api/shipping-options', shippingPayload(['is_default' => true, 'is_active' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_default');
    });

    it('rejects invalid text and number fields', function (string $field, mixed $value) {
        $this->postJson('/api/shipping-options', shippingPayload([$field => $value]))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    })->with([
        ['name', str_repeat('a', 101)],
        ['description', str_repeat('a', 256)],
        ['estimated_days', str_repeat('a', 51)],
        ['sort_order', -1],
        ['sort_order', 'abc'],
        ['is_active', 'maybe'],
    ]);

    it('ignores fields that are not allowed', function () {
        $this->postJson('/api/shipping-options', shippingPayload(['id' => 999]))->assertCreated();

        expect(ShippingOption::firstOrFail()->id)->not->toBe(999);
    });

    // ---------- List and show ----------

    it('lists the options in the order the admin set', function () {
        ShippingOption::factory()->create(['code' => 'third', 'sort_order' => 30]);
        ShippingOption::factory()->create(['code' => 'first', 'sort_order' => 10]);
        ShippingOption::factory()->create(['code' => 'second', 'sort_order' => 20]);

        $codes = $this->getJson('/api/shipping-options')
            ->assertOk()
            ->assertJsonPath('message', 'Shipping options retrieved successfully.')
            ->assertJsonPath('meta.total', 3)
            ->json('data.*.code');

        expect($codes)->toBe(['first', 'second', 'third']);
    });

    it('filters by is_active and searches by name', function () {
        ShippingOption::factory()->create(['name' => 'Express delivery']);
        ShippingOption::factory()->inactive()->create(['name' => 'Old courier']);

        $this->getJson('/api/shipping-options?is_active=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Old courier');
        $this->getJson('/api/shipping-options?search=express')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Express delivery');
        $this->getJson('/api/shipping-options?is_active=maybe')->assertStatus(422)->assertJsonValidationErrors('is_active');
    });

    it('does not list deleted options', function () {
        ShippingOption::factory()->create()->delete();

        $this->getJson('/api/shipping-options')->assertOk()->assertJsonCount(0, 'data');
    });

    it('shows an option', function () {
        $option = ShippingOption::factory()->create(['code' => 'inside-city', 'fee' => 60]);

        $this->getJson("/api/shipping-options/{$option->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Shipping option retrieved successfully.')
            ->assertJsonPath('data.code', 'inside-city')
            ->assertJsonPath('data.fee', '60.00');
    });

    it('returns 404 for a missing or deleted option', function () {
        $deleted = ShippingOption::factory()->create();
        $deleted->delete();

        $this->getJson('/api/shipping-options/999')->assertNotFound()->assertJsonPath('message', 'Resource not found.');
        $this->getJson("/api/shipping-options/{$deleted->id}")->assertNotFound();
        $this->putJson('/api/shipping-options/999', ['fee' => 10])->assertNotFound();
        $this->deleteJson('/api/shipping-options/999')->assertNotFound();
    });

    // ---------- Update ----------

    it('updates the fee and the name', function () {
        $option = ShippingOption::factory()->create(['fee' => 60]);

        $this->putJson("/api/shipping-options/{$option->id}", ['fee' => 70, 'name' => 'City delivery'])
            ->assertOk()
            ->assertJsonPath('message', 'Shipping option updated successfully.')
            ->assertJsonPath('data.fee', '70.00')
            ->assertJsonPath('data.name', 'City delivery');
    });

    it('does not let the code be changed', function () {
        $option = ShippingOption::factory()->create(['code' => 'inside-city']);

        $this->putJson("/api/shipping-options/{$option->id}", ['code' => 'other-code'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code')
            ->assertJsonPath('errors.code.0', 'The code cannot be changed. Create a new delivery option instead.');

        expect($option->fresh()->code)->toBe('inside-city');
    });

    it('rejects invalid values on update', function (string $field, mixed $value) {
        $option = ShippingOption::factory()->create();

        $this->putJson("/api/shipping-options/{$option->id}", [$field => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    })->with([
        ['fee', -1],
        ['fee', 'abc'],
        ['name', ''],
        ['min_order_amount', 0],
        ['sort_order', -1],
    ]);

    it('changes the default and clears the old one', function () {
        $old = ShippingOption::factory()->asDefault()->create();
        $new = ShippingOption::factory()->create();

        $this->putJson("/api/shipping-options/{$new->id}", ['is_default' => true])
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        expect($old->fresh()->is_default)->toBeFalse();
        expect(ShippingOption::where('is_default', true)->count())->toBe(1);
    });

    it('does not let the default option be switched off directly', function () {
        $default = ShippingOption::factory()->asDefault()->create();

        $this->putJson("/api/shipping-options/{$default->id}", ['is_default' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_default');
    });

    it('does not let an inactive option become the default', function () {
        $option = ShippingOption::factory()->inactive()->create();

        $this->putJson("/api/shipping-options/{$option->id}", ['is_default' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');
    });

    it('does not let the default option be turned off', function () {
        $default = ShippingOption::factory()->asDefault()->create();
        ShippingOption::factory()->create();

        $this->putJson("/api/shipping-options/{$default->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');
    });

    it('does not let the default option get a minimum order', function () {
        $default = ShippingOption::factory()->asDefault()->create();

        $this->putJson("/api/shipping-options/{$default->id}", ['min_order_amount' => 500])
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_order_amount');
    });

    it('lets another option have a minimum order and be turned off', function () {
        ShippingOption::factory()->asDefault()->create();
        $other = ShippingOption::factory()->create();

        $this->putJson("/api/shipping-options/{$other->id}", ['min_order_amount' => 3000, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.min_order_amount', '3000.00')
            ->assertJsonPath('data.is_active', false);
    });

    it('does not let the last active option be turned off', function () {
        $only = ShippingOption::factory()->create();

        $this->putJson("/api/shipping-options/{$only->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active')
            ->assertJsonPath('errors.is_active.0', 'At least one delivery option must stay active.');
    });

    it('removes the minimum order', function () {
        ShippingOption::factory()->asDefault()->create();
        $free = ShippingOption::factory()->freeFrom(5000)->create();

        $this->putJson("/api/shipping-options/{$free->id}", ['min_order_amount' => null])
            ->assertOk()
            ->assertJsonPath('data.min_order_amount', null);
    });

    // ---------- Delete ----------

    it('deletes an option and keeps its code reserved', function () {
        ShippingOption::factory()->asDefault()->create();
        $option = ShippingOption::factory()->create(['code' => 'express']);

        $this->deleteJson("/api/shipping-options/{$option->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Shipping option deleted successfully.')
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('shipping_options', ['id' => $option->id]);

        $this->postJson('/api/shipping-options', shippingPayload(['code' => 'express']))
            ->assertStatus(422)->assertJsonValidationErrors('code');
    });

    it('does not let the default option be deleted', function () {
        $default = ShippingOption::factory()->asDefault()->create();
        ShippingOption::factory()->create();

        $this->deleteJson("/api/shipping-options/{$default->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'The default delivery option cannot be deleted. Make another option the default first.');

        $this->assertNotSoftDeleted('shipping_options', ['id' => $default->id]);
    });

    it('does not let the last active option be deleted', function () {
        $only = ShippingOption::factory()->create();
        ShippingOption::factory()->inactive()->create();

        $this->deleteJson("/api/shipping-options/{$only->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'At least one delivery option must stay active.');

        $this->assertNotSoftDeleted('shipping_options', ['id' => $only->id]);
    });

    it('lets an inactive option be deleted', function () {
        ShippingOption::factory()->asDefault()->create();
        $inactive = ShippingOption::factory()->inactive()->create();

        $this->deleteJson("/api/shipping-options/{$inactive->id}")->assertOk();
    });
});
