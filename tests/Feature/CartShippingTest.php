<?php

use App\Models\ProductVariant;
use App\Models\ShippingOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function cartWith(object $test, ProductVariant $variant, int $quantity = 1): void
{
    $test->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => $quantity])->assertCreated();
}

it('rejects guests when choosing a delivery option', function () {
    $this->putJson('/api/cart/shipping', ['shipping_option_id' => 1])->assertUnauthorized();
});

describe('when logged in', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->ship = seedShipping();
    });

    // ---------- Currency ----------

    it('shows BDT as the currency, also for an empty cart', function () {
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.currency', 'BDT');

        cartWith($this, buyable(1000));

        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.currency', 'BDT');
    });

    it('reads the currency from the config', function () {
        config(['shop.currency' => 'USD']);

        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.currency', 'USD');
    });

    // ---------- Options and the default ----------

    it('shows the delivery options of a cart', function () {
        cartWith($this, buyable(1000));

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonCount(3, 'data.shipping.options')
            ->assertJsonPath('data.shipping.options.0.code', 'inside-city')
            ->assertJsonPath('data.shipping.options.0.fee', '60.00')
            ->assertJsonPath('data.shipping.options.1.code', 'outside-city')
            ->assertJsonPath('data.shipping.options.1.fee', '120.00')
            ->assertJsonPath('data.shipping.options.2.code', 'free-delivery')
            ->assertJsonPath('data.shipping.options.2.fee', '0.00');
    });

    it('uses the default delivery option until the customer chooses another', function () {
        cartWith($this, buyable(1000));

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.shipping.selected.code', 'inside-city')
            ->assertJsonPath('data.shipping.options.0.selected', true)
            ->assertJsonPath('data.shipping.options.1.selected', false)
            ->assertJsonPath('data.shipping_option_id', $this->ship['inside']->id)
            ->assertJsonPath('data.delivery_fee', '60.00')
            ->assertJsonPath('data.grand_total', '1060.00')
            ->assertJsonPath('data.ready', true);
    });

    it('lists the options in the order the admin set', function () {
        $this->ship['free']->update(['sort_order' => 5]);

        $codes = $this->getJson('/api/cart')->json('data.shipping.options.*.code');

        expect($codes)->toBe(['free-delivery', 'inside-city', 'outside-city']);
    });

    it('shows no delivery fee and no problem for an empty cart', function () {
        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.delivery_fee', '0.00')
            ->assertJsonPath('data.grand_total', '0.00')
            ->assertJsonCount(3, 'data.shipping.options')
            ->assertJsonPath('data.issues.0.type', 'cart_empty')
            ->assertJsonCount(1, 'data.issues');
    });

    it('does not share a delivery choice between customers', function () {
        $variant = buyable(1000);
        cartWith($this, $variant);
        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['outside']->id])->assertOk();

        Sanctum::actingAs(User::factory()->create());
        cartWith($this, $variant);

        $this->getJson('/api/cart')->assertJsonPath('data.shipping.selected.code', 'inside-city');
    });

    // ---------- Choosing an option ----------

    it('lets the customer choose outside city delivery', function () {
        cartWith($this, buyable(1000));

        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['outside']->id])
            ->assertOk()
            ->assertJsonPath('message', 'Delivery option selected.')
            ->assertJsonPath('data.shipping.selected.code', 'outside-city')
            ->assertJsonPath('data.shipping.options.1.selected', true)
            ->assertJsonPath('data.delivery_fee', '120.00')
            ->assertJsonPath('data.grand_total', '1120.00');

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'shipping_option_id' => $this->ship['outside']->id,
        ]);
    });

    it('lets the customer choose before the cart has items', function () {
        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['outside']->id])
            ->assertOk()
            ->assertJsonPath('data.shipping.selected.code', 'outside-city')
            ->assertJsonPath('data.delivery_fee', '0.00');

        cartWith($this, buyable(1000));

        $this->getJson('/api/cart')
            ->assertJsonPath('data.shipping.selected.code', 'outside-city')
            ->assertJsonPath('data.delivery_fee', '120.00');
    });

    it('keeps the delivery choice when items change', function () {
        cartWith($this, buyable(1000));
        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['outside']->id])->assertOk();

        cartWith($this, buyable(500));

        $this->getJson('/api/cart')
            ->assertJsonPath('data.shipping.selected.code', 'outside-city')
            ->assertJsonPath('data.delivery_fee', '120.00');
    });

    it('refuses an option that is inactive, deleted or unknown', function () {
        cartWith($this, buyable(1000));
        $inactive = ShippingOption::factory()->inactive()->create();
        $deleted = ShippingOption::factory()->create();
        $deleted->delete();

        foreach ([$inactive->id, $deleted->id, 9999] as $id) {
            $this->putJson('/api/cart/shipping', ['shipping_option_id' => $id])
                ->assertStatus(422)
                ->assertJsonPath('message', 'This delivery option is not available.');
        }
    });

    it('rejects an invalid choice request', function (array $body) {
        $this->putJson('/api/cart/shipping', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipping_option_id');
    })->with([
        'missing' => [[]],
        'text' => [['shipping_option_id' => 'abc']],
        'zero' => [['shipping_option_id' => 0]],
        'decimal' => [['shipping_option_id' => 1.5]],
    ]);

    // ---------- Free delivery and the minimum ----------

    it('shows free delivery as not available below the minimum', function () {
        cartWith($this, buyable(1000));

        $this->getJson('/api/cart')
            ->assertJsonPath('data.shipping.options.2.available', false)
            ->assertJsonPath('data.shipping.options.2.unavailable_reason', 'Available for orders of 5000.00 or more.')
            ->assertJsonPath('data.shipping.options.0.available', true)
            ->assertJsonPath('data.shipping.options.0.unavailable_reason', null);
    });

    it('lets the customer choose free delivery when the goods total reaches the minimum', function () {
        cartWith($this, buyable(5000));

        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['free']->id])
            ->assertOk()
            ->assertJsonPath('data.shipping.selected.code', 'free-delivery')
            ->assertJsonPath('data.delivery_fee', '0.00')
            ->assertJsonPath('data.grand_total', '5000.00')
            ->assertJsonPath('data.ready', true);
    });

    it('refuses free delivery below the minimum', function () {
        cartWith($this, buyable(4999));

        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['free']->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This delivery option is available for orders of 5000.00 or more.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('carts', ['user_id' => $this->user->id, 'shipping_option_id' => null]);
    });

    it('refuses free delivery for an empty cart', function () {
        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['free']->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This delivery option is available for orders of 5000.00 or more.');
    });

    it('measures the minimum after the coupon discount', function () {
        cartWith($this, buyable(5000));
        makeCoupon(['code' => 'SAVE10']);
        $this->putJson('/api/cart/coupon', ['code' => 'SAVE10'])->assertOk();

        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['free']->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This delivery option is available for orders of 5000.00 or more.');
    });

    it('shows a problem when a coupon takes the total below the free delivery minimum', function () {
        cartWith($this, buyable(5000));
        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['free']->id])->assertOk();
        makeCoupon(['code' => 'SAVE10']);

        $this->putJson('/api/cart/coupon', ['code' => 'SAVE10'])
            ->assertOk()
            ->assertJsonPath('data.shipping.selected', null)
            ->assertJsonPath('data.delivery_fee', '0.00')
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.type', 'shipping_unavailable');

        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['inside']->id])
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.grand_total', '4560.00');
    });

    // ---------- The admin changes the options ----------

    it('shows the new fee when the admin changes it', function () {
        cartWith($this, buyable(1000));
        $this->ship['inside']->update(['fee' => 70]);

        $this->getJson('/api/cart')
            ->assertJsonPath('data.delivery_fee', '70.00')
            ->assertJsonPath('data.grand_total', '1070.00');
    });

    it('shows a problem when the chosen option is turned off', function () {
        cartWith($this, buyable(1000));
        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['outside']->id])->assertOk();
        $this->ship['outside']->update(['is_active' => false]);

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.shipping.selected', null)
            ->assertJsonPath('data.delivery_fee', '0.00')
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.type', 'shipping_unavailable');

        $this->postJson('/api/cart/prepare')
            ->assertStatus(422)
            ->assertJsonPath('errors.0.type', 'shipping_unavailable');
    });

    it('shows a problem when the chosen option was deleted', function () {
        cartWith($this, buyable(1000));
        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['outside']->id])->assertOk();
        $this->ship['outside']->delete();

        $this->getJson('/api/cart')
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.type', 'shipping_unavailable');
    });

    it('asks the customer to choose when there is no default option', function () {
        $this->ship['inside']->update(['is_default' => false]);
        cartWith($this, buyable(1000));

        $this->getJson('/api/cart')
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.type', 'shipping_required')
            ->assertJsonPath('data.delivery_fee', '0.00');

        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['inside']->id])
            ->assertOk()
            ->assertJsonPath('data.ready', true);
    });

    it('asks the customer to choose when no delivery option exists', function () {
        ShippingOption::query()->delete();
        cartWith($this, buyable(1000));

        $this->getJson('/api/cart')
            ->assertJsonPath('data.shipping.options', [])
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.issues.0.type', 'shipping_required');
    });

    // ---------- Checkout preparation ----------

    it('adds the delivery fee to the checkout total and changes the hash when the option changes', function () {
        cartWith($this, buyable(1000));

        $first = $this->postJson('/api/cart/prepare')
            ->assertOk()
            ->assertJsonPath('data.shipping_option_id', $this->ship['inside']->id)
            ->assertJsonPath('data.expected_total', '1060.00')
            ->json('data.cart_hash');

        $this->putJson('/api/cart/shipping', ['shipping_option_id' => $this->ship['outside']->id])->assertOk();

        $second = $this->postJson('/api/cart/prepare')
            ->assertOk()
            ->assertJsonPath('data.shipping_option_id', $this->ship['outside']->id)
            ->assertJsonPath('data.expected_total', '1120.00')
            ->json('data.cart_hash');

        expect($second)->not->toBe($first);
    });

    it('changes the hash when the admin changes the fee of the chosen option', function () {
        cartWith($this, buyable(1000));
        $before = $this->postJson('/api/cart/prepare')->assertOk()->json('data.cart_hash');

        $this->ship['inside']->update(['fee' => 80]);

        $this->postJson('/api/cart/prepare')
            ->assertOk()
            ->assertJsonPath('data.expected_total', '1080.00')
            ->assertJsonPath('data.cart_hash', fn ($hash) => $hash !== $before);
    });
});
