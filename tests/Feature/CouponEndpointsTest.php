<?php

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function couponPayload(array $overrides = []): array
{
    return array_merge(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10], $overrides);
}

// ---------- Access ----------

it('rejects guests on every coupon route', function () {
    $coupon = makeCoupon();

    $this->getJson('/api/coupons')->assertUnauthorized();
    $this->postJson('/api/coupons', [])->assertUnauthorized();
    $this->getJson("/api/coupons/{$coupon->id}")->assertUnauthorized();
    $this->putJson("/api/coupons/{$coupon->id}", [])->assertUnauthorized();
    $this->deleteJson("/api/coupons/{$coupon->id}")->assertUnauthorized();
    $this->getJson("/api/coupons/{$coupon->id}/redemptions")->assertUnauthorized();
    $this->postJson('/api/coupons/quote', [])->assertUnauthorized();
});

it('rejects customers and staff on the admin coupon routes', function (string $role) {
    $user = $role === 'customer' ? User::factory()->create() : User::factory()->staff()->create();
    Sanctum::actingAs($user);
    $coupon = makeCoupon();

    $this->getJson('/api/coupons')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have permission to do this.');
    $this->postJson('/api/coupons', couponPayload())->assertForbidden();
    $this->getJson("/api/coupons/{$coupon->id}")->assertForbidden();
    $this->putJson("/api/coupons/{$coupon->id}", ['value' => 20])->assertForbidden();
    $this->deleteJson("/api/coupons/{$coupon->id}")->assertForbidden();
    $this->getJson("/api/coupons/{$coupon->id}/redemptions")->assertForbidden();

    expect(Coupon::count())->toBe(1);
})->with(['customer', 'staff']);

describe('when admin', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->admin()->create());
    });

    // ---------- Create ----------

    it('creates a percentage coupon with the defaults', function () {
        $this->postJson('/api/coupons', couponPayload())
            ->assertCreated()
            ->assertJsonPath('message', 'Coupon created successfully.')
            ->assertJsonPath('data.code', 'SAVE10')
            ->assertJsonPath('data.type', 'percentage')
            ->assertJsonPath('data.value', '10.00')
            ->assertJsonPath('data.max_discount', null)
            ->assertJsonPath('data.usage_limit_total', null)
            ->assertJsonPath('data.usage_limit_per_user', 1)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.used_count', 0);

        $this->assertDatabaseHas('coupons', ['code' => 'SAVE10']);
    });

    it('creates a fixed coupon with a minimum order and limits', function () {
        $this->postJson('/api/coupons', couponPayload([
            'code' => 'FLAT200',
            'type' => 'fixed',
            'value' => 200,
            'min_order_amount' => 1000,
            'usage_limit_total' => 50,
            'usage_limit_per_user' => 2,
            'starts_at' => now()->addDay()->toIso8601String(),
            'expires_at' => now()->addDays(10)->toIso8601String(),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.type', 'fixed')
            ->assertJsonPath('data.value', '200.00')
            ->assertJsonPath('data.min_order_amount', '1000.00')
            ->assertJsonPath('data.usage_limit_total', 50)
            ->assertJsonPath('data.usage_limit_per_user', 2)
            ->assertJsonPath('data.state', 'scheduled');
    });

    it('creates a percentage coupon with a maximum discount', function () {
        $this->postJson('/api/coupons', couponPayload(['max_discount' => 500]))
            ->assertCreated()
            ->assertJsonPath('data.max_discount', '500.00');
    });

    it('trims the code and saves it in capital letters', function () {
        $this->postJson('/api/coupons', couponPayload(['code' => '  save10 ']))
            ->assertCreated()
            ->assertJsonPath('data.code', 'SAVE10');
    });

    it('lets the per-customer limit be empty for unlimited use', function () {
        $this->postJson('/api/coupons', couponPayload(['usage_limit_per_user' => null]))
            ->assertCreated()
            ->assertJsonPath('data.usage_limit_per_user', null);
    });

    it('requires code, type and value', function () {
        $this->postJson('/api/coupons', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'type', 'value']);
    });

    it('rejects an invalid code', function (string $code) {
        $this->postJson('/api/coupons', couponPayload(['code' => $code]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    })->with(['AB', 'SAVE 10', 'SAVE$10', '-SAVE10', 'A-very-long-code-with-more-than-32-characters']);

    it('rejects a duplicate code in any letter case', function () {
        makeCoupon(['code' => 'SAVE10']);

        $this->postJson('/api/coupons', couponPayload(['code' => 'save10']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        expect(Coupon::count())->toBe(1);
    });

    it('keeps the code of a deleted coupon reserved', function () {
        $coupon = makeCoupon(['code' => 'SAVE10']);
        $this->deleteJson("/api/coupons/{$coupon->id}")->assertOk();

        $this->postJson('/api/coupons', couponPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    });

    it('rejects an invalid type', function () {
        $this->postJson('/api/coupons', couponPayload(['type' => 'banana']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    });

    it('rejects an invalid value', function (mixed $value) {
        $this->postJson('/api/coupons', couponPayload(['value' => $value]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');
    })->with([0, -5, 'abc', 10.555]);

    it('rejects a percentage above 100 but allows exactly 100', function () {
        $this->postJson('/api/coupons', couponPayload(['value' => 100.01]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');

        $this->postJson('/api/coupons', couponPayload(['value' => 100]))->assertCreated();
    });

    it('allows a fixed value above 100', function () {
        $this->postJson('/api/coupons', couponPayload(['type' => 'fixed', 'value' => 150]))->assertCreated();
    });

    it('rejects a maximum discount on a fixed coupon', function () {
        $this->postJson('/api/coupons', couponPayload(['type' => 'fixed', 'value' => 50, 'max_discount' => 100]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_discount');
    });

    it('rejects a maximum discount of zero', function () {
        $this->postJson('/api/coupons', couponPayload(['max_discount' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_discount');
    });

    it('rejects a negative minimum order', function () {
        $this->postJson('/api/coupons', couponPayload(['min_order_amount' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('min_order_amount');
    });

    it('rejects an expiry that is not after the start', function (string $expires) {
        $this->postJson('/api/coupons', couponPayload([
            'starts_at' => '2030-06-01 10:00:00',
            'expires_at' => $expires,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expires_at');
    })->with(['2030-05-01 10:00:00', '2030-06-01 10:00:00']);

    it('accepts an expiry without a start', function () {
        $this->postJson('/api/coupons', couponPayload(['expires_at' => now()->addDays(5)->toIso8601String()]))
            ->assertCreated();
    });

    it('rejects invalid usage limits', function (string $field, mixed $value) {
        $this->postJson('/api/coupons', couponPayload([$field => $value]))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    })->with([
        ['usage_limit_total', 0],
        ['usage_limit_total', 1.5],
        ['usage_limit_total', 'abc'],
        ['usage_limit_per_user', 0],
        ['usage_limit_per_user', -1],
    ]);

    it('rejects an invalid is_active', function () {
        $this->postJson('/api/coupons', couponPayload(['is_active' => 'maybe']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');
    });

    it('ignores fields that are not allowed', function () {
        $this->postJson('/api/coupons', couponPayload(['id' => 999, 'used_count' => 50]))
            ->assertCreated()
            ->assertJsonPath('data.used_count', 0);

        expect(Coupon::firstOrFail()->id)->not->toBe(999);
    });

    // ---------- List ----------

    it('lists coupons with pagination, newest first', function () {
        Coupon::factory()->count(20)->create();

        $this->getJson('/api/coupons')
            ->assertOk()
            ->assertJsonPath('message', 'Coupons retrieved successfully.')
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('searches coupons by code', function () {
        makeCoupon(['code' => 'EID2030']);
        makeCoupon(['code' => 'WINTER']);

        $this->getJson('/api/coupons?search=eid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'EID2030');
    });

    it('filters coupons by type', function () {
        makeCoupon(['code' => 'PERCENT1']);
        makeCoupon(['code' => 'FIXED001', 'type' => 'fixed', 'value' => 50]);

        $this->getJson('/api/coupons?type=fixed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'FIXED001');
    });

    it('filters coupons by state', function (string $state, string $code) {
        Coupon::factory()->create(['code' => 'ACTIVE01']);
        Coupon::factory()->inactive()->create(['code' => 'INACTIVE']);
        Coupon::factory()->scheduled()->create(['code' => 'SCHEDULE']);
        Coupon::factory()->expired()->create(['code' => 'EXPIRED1']);

        $this->getJson("/api/coupons?state={$state}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $code)
            ->assertJsonPath('data.0.state', $state);
    })->with([
        ['active', 'ACTIVE01'],
        ['inactive', 'INACTIVE'],
        ['scheduled', 'SCHEDULE'],
        ['expired', 'EXPIRED1'],
    ]);

    it('rejects an invalid state or type filter', function () {
        $this->getJson('/api/coupons?state=banana')->assertStatus(422)->assertJsonValidationErrors('state');
        $this->getJson('/api/coupons?type=banana')->assertStatus(422)->assertJsonValidationErrors('type');
    });

    it('counts held and counted uses but not released or expired ones', function () {
        $coupon = makeCoupon(['usage_limit_per_user' => null]);
        makeRedemption($coupon, null, ['status' => 'reserved']);
        makeRedemption($coupon, null, ['status' => 'redeemed']);
        makeRedemption($coupon, null, ['status' => 'released']);
        makeRedemption($coupon, null, ['status' => 'expired']);

        $this->getJson('/api/coupons')->assertJsonPath('data.0.used_count', 2);
    });

    it('does not list deleted coupons', function () {
        makeCoupon()->delete();

        $this->getJson('/api/coupons')->assertOk()->assertJsonCount(0, 'data');
    });

    // ---------- Show ----------

    it('shows a coupon with its used count', function () {
        $coupon = makeCoupon(['code' => 'SAVE10']);
        makeRedemption($coupon);

        $this->getJson("/api/coupons/{$coupon->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Coupon retrieved successfully.')
            ->assertJsonPath('data.code', 'SAVE10')
            ->assertJsonPath('data.used_count', 1);
    });

    it('returns 404 for a missing or deleted coupon', function () {
        $deleted = makeCoupon();
        $deleted->delete();

        $this->getJson('/api/coupons/999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Resource not found.');
        $this->getJson("/api/coupons/{$deleted->id}")->assertNotFound();
    });

    // ---------- Update ----------

    it('updates a coupon', function () {
        $coupon = makeCoupon(['value' => 10]);

        $this->putJson("/api/coupons/{$coupon->id}", ['value' => 15, 'min_order_amount' => 500])
            ->assertOk()
            ->assertJsonPath('message', 'Coupon updated successfully.')
            ->assertJsonPath('data.value', '15.00')
            ->assertJsonPath('data.min_order_amount', '500.00');
    });

    it('does not let the code be changed', function () {
        $coupon = makeCoupon(['code' => 'SAVE10']);

        $this->putJson("/api/coupons/{$coupon->id}", ['code' => 'OTHER'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code')
            ->assertJsonPath('errors.code.0', 'The coupon code cannot be changed. Create a new coupon instead.');

        expect($coupon->fresh()->code)->toBe('SAVE10');
    });

    it('rejects a percentage above 100 on update but not on a fixed coupon', function () {
        $percentage = makeCoupon(['code' => 'PERCENT1']);
        $fixed = makeCoupon(['code' => 'FIXED001', 'type' => 'fixed', 'value' => 50]);

        $this->putJson("/api/coupons/{$percentage->id}", ['value' => 150])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');

        $this->putJson("/api/coupons/{$fixed->id}", ['value' => 150])->assertOk();
    });

    it('rejects changing a large fixed coupon to a percentage without a new value', function () {
        $coupon = makeCoupon(['type' => 'fixed', 'value' => 500]);

        $this->putJson("/api/coupons/{$coupon->id}", ['type' => 'percentage'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->putJson("/api/coupons/{$coupon->id}", ['type' => 'percentage', 'value' => 20])
            ->assertOk()
            ->assertJsonPath('data.type', 'percentage');
    });

    it('rejects a maximum discount on a fixed coupon on update', function () {
        $coupon = makeCoupon(['type' => 'fixed', 'value' => 50]);

        $this->putJson("/api/coupons/{$coupon->id}", ['max_discount' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_discount');
    });

    it('clears the maximum discount when a coupon becomes fixed', function () {
        $coupon = makeCoupon(['value' => 50, 'max_discount' => 300]);

        $this->putJson("/api/coupons/{$coupon->id}", ['type' => 'fixed', 'value' => 50])
            ->assertOk()
            ->assertJsonPath('data.max_discount', null);
    });

    it('does not let the total limit go below the uses so far', function () {
        $coupon = makeCoupon(['usage_limit_total' => 5, 'usage_limit_per_user' => null]);
        makeRedemption($coupon, null, ['status' => 'redeemed']);
        makeRedemption($coupon, null, ['status' => 'redeemed']);
        makeRedemption($coupon, null, ['status' => 'reserved']);

        $this->putJson("/api/coupons/{$coupon->id}", ['usage_limit_total' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('usage_limit_total');

        $this->putJson("/api/coupons/{$coupon->id}", ['usage_limit_total' => 3])->assertOk();
        $this->putJson("/api/coupons/{$coupon->id}", ['usage_limit_total' => null])
            ->assertOk()
            ->assertJsonPath('data.usage_limit_total', null);
    });

    it('does not count released uses when checking the total limit', function () {
        $coupon = makeCoupon(['usage_limit_total' => 5, 'usage_limit_per_user' => null]);
        makeRedemption($coupon, null, ['status' => 'redeemed']);
        makeRedemption($coupon, null, ['status' => 'redeemed']);
        makeRedemption($coupon, null, ['status' => 'released']);

        $this->putJson("/api/coupons/{$coupon->id}", ['usage_limit_total' => 2])->assertOk();
    });

    it('rejects an expiry before the saved start', function () {
        $coupon = makeCoupon(['starts_at' => '2030-06-01 10:00:00', 'expires_at' => '2030-07-01 10:00:00']);

        $this->putJson("/api/coupons/{$coupon->id}", ['expires_at' => '2030-05-01 10:00:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expires_at');
    });

    it('removes the expiry', function () {
        $coupon = makeCoupon(['expires_at' => now()->addDays(5)]);

        $this->putJson("/api/coupons/{$coupon->id}", ['expires_at' => null])
            ->assertOk()
            ->assertJsonPath('data.expires_at', null);
    });

    it('deactivates and reactivates a coupon', function () {
        $coupon = makeCoupon();

        $this->putJson("/api/coupons/{$coupon->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.state', 'inactive');

        $this->putJson("/api/coupons/{$coupon->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.state', 'active');
    });

    it('returns 404 when updating a missing or deleted coupon', function () {
        $deleted = makeCoupon();
        $deleted->delete();

        $this->putJson('/api/coupons/999', ['value' => 20])->assertNotFound();
        $this->putJson("/api/coupons/{$deleted->id}", ['value' => 20])->assertNotFound();
    });

    // ---------- Delete ----------

    it('deletes a coupon', function () {
        $coupon = makeCoupon();

        $this->deleteJson("/api/coupons/{$coupon->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Coupon deleted successfully.')
            ->assertJsonPath('data', null);

        $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);
    });

    it('blocks deleting a coupon that has uses waiting for payment', function () {
        $coupon = makeCoupon();
        makeRedemption($coupon, null, ['status' => 'reserved']);

        $this->deleteJson("/api/coupons/{$coupon->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This coupon has uses waiting for payment. Deactivate it instead, or wait until they end.');

        $this->assertNotSoftDeleted('coupons', ['id' => $coupon->id]);
    });

    it('deletes a coupon that only has finished uses', function () {
        $coupon = makeCoupon(['usage_limit_per_user' => null]);
        makeRedemption($coupon, null, ['status' => 'redeemed']);
        makeRedemption($coupon, null, ['status' => 'released']);

        $this->deleteJson("/api/coupons/{$coupon->id}")->assertOk();
    });

    it('returns 404 when deleting a missing coupon', function () {
        $this->deleteJson('/api/coupons/999')->assertNotFound();
    });

    // ---------- Redemptions ----------

    it('lists the uses of a coupon, newest first', function () {
        $coupon = makeCoupon(['usage_limit_per_user' => null]);
        $user = User::factory()->create(['name' => 'Rahim']);
        makeRedemption($coupon, $user, ['discount_amount' => 240, 'status' => 'redeemed']);
        makeRedemption($coupon, null, ['status' => 'released']);
        makeRedemption(makeCoupon(['code' => 'OTHER01']));

        $this->getJson("/api/coupons/{$coupon->id}/redemptions")
            ->assertOk()
            ->assertJsonPath('message', 'Coupon redemptions retrieved successfully.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.1.user.name', 'Rahim')
            ->assertJsonPath('data.1.discount_amount', '240.00')
            ->assertJsonPath('data.1.status', 'redeemed');
    });

    it('filters the uses by status', function () {
        $coupon = makeCoupon(['usage_limit_per_user' => null]);
        makeRedemption($coupon, null, ['status' => 'redeemed']);
        makeRedemption($coupon, null, ['status' => 'released']);

        $this->getJson("/api/coupons/{$coupon->id}/redemptions?status=released")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'released');
    });

    it('rejects an invalid status filter', function () {
        $coupon = makeCoupon();

        $this->getJson("/api/coupons/{$coupon->id}/redemptions?status=banana")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    });

    it('paginates the uses 20 per page', function () {
        $coupon = makeCoupon(['usage_limit_per_user' => null]);

        foreach (range(1, 25) as $i) {
            makeRedemption($coupon);
        }

        $this->getJson("/api/coupons/{$coupon->id}/redemptions")
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2);
    });

    it('still shows the uses of a deleted coupon', function () {
        $coupon = makeCoupon();
        makeRedemption($coupon);
        $coupon->delete();

        $this->getJson("/api/coupons/{$coupon->id}/redemptions")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('returns 404 for the uses of a coupon that never existed', function () {
        $this->getJson('/api/coupons/999/redemptions')->assertNotFound();
    });
});

// ---------- Quote ----------

describe('quote', function () {
    beforeEach(function () {
        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer);
    });

    it('lets a customer quote a coupon and returns the breakdown', function () {
        $variant = buyable(1200);
        makeCoupon(['code' => 'SAVE10', 'value' => 10]);

        $this->postJson('/api/coupons/quote', ['code' => 'SAVE10', 'items' => cartOf([[$variant, 2]])])
            ->assertOk()
            ->assertJsonPath('message', 'Coupon is valid for this order.')
            ->assertJsonPath('data.code', 'SAVE10')
            ->assertJsonPath('data.type', 'percentage')
            ->assertJsonPath('data.items_total', '2400.00')
            ->assertJsonPath('data.discount', '240.00')
            ->assertJsonPath('data.total_after_discount', '2160.00')
            ->assertJsonPath('data.lines.0.variant_id', $variant->id)
            ->assertJsonPath('data.lines.0.unit_price', '1200.00')
            ->assertJsonPath('data.lines.0.discount', '240.00')
            ->assertJsonPath('data.lines.0.line_payable', '2160.00');
    });

    it('accepts a code in small letters with spaces', function () {
        $variant = buyable(1000);
        makeCoupon(['code' => 'SAVE10']);

        $this->postJson('/api/coupons/quote', ['code' => ' save10 ', 'items' => cartOf([[$variant, 1]])])
            ->assertOk()
            ->assertJsonPath('data.code', 'SAVE10');
    });

    it('uses the prices from the database and ignores a price sent by the client', function () {
        $variant = buyable(1000);
        makeCoupon(['code' => 'SAVE10']);

        $this->postJson('/api/coupons/quote', [
            'code' => 'SAVE10',
            'items' => [['variant_id' => $variant->id, 'quantity' => 1, 'price' => 1, 'discount' => 999]],
        ])
            ->assertOk()
            ->assertJsonPath('data.items_total', '1000.00')
            ->assertJsonPath('data.discount', '100.00');
    });

    it('does not use up the coupon', function () {
        $variant = buyable(1000);
        $coupon = makeCoupon(['code' => 'SAVE10']);

        $this->postJson('/api/coupons/quote', ['code' => 'SAVE10', 'items' => cartOf([[$variant, 1]])])->assertOk();

        expect($coupon->redemptions()->count())->toBe(0);
    });

    it('gives the same error for every code that cannot be used', function (string $case) {
        $variant = buyable(1000);

        match ($case) {
            'unknown' => null,
            'inactive' => makeCoupon(['code' => 'THECODE', 'is_active' => false]),
            'expired' => makeCoupon(['code' => 'THECODE', 'starts_at' => now()->subDays(5), 'expires_at' => now()->subDay()]),
            'scheduled' => makeCoupon(['code' => 'THECODE', 'starts_at' => now()->addDay()]),
            'limit reached' => makeRedemption(makeCoupon(['code' => 'THECODE', 'usage_limit_total' => 1])),
            'used by this customer' => makeRedemption(makeCoupon(['code' => 'THECODE']), $this->customer),
        };

        $this->postJson('/api/coupons/quote', ['code' => 'THECODE', 'items' => cartOf([[$variant, 1]])])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This coupon code is not valid.')
            ->assertJsonPath('data', null);
    })->with(['unknown', 'inactive', 'expired', 'scheduled', 'limit reached', 'used by this customer']);

    it('still lets other customers use a coupon that this customer used up', function () {
        $variant = buyable(1000);
        makeRedemption(makeCoupon(['code' => 'ONCE']), User::factory()->create());

        $this->postJson('/api/coupons/quote', ['code' => 'ONCE', 'items' => cartOf([[$variant, 1]])])->assertOk();
    });

    it('names the minimum when the order is too small', function () {
        $variant = buyable(400);
        makeCoupon(['code' => 'BIGONLY', 'min_order_amount' => 500]);

        $this->postJson('/api/coupons/quote', ['code' => 'BIGONLY', 'items' => cartOf([[$variant, 1]])])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The order total must be at least 500.00 to use this coupon.');
    });

    it('refuses items that cannot be bought', function () {
        makeCoupon(['code' => 'SAVE10']);

        $this->postJson('/api/coupons/quote', ['code' => 'SAVE10', 'items' => [['variant_id' => 999, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'One or more items are not available.');
    });

    it('rejects an invalid request', function (array $body, string $field) {
        $this->postJson('/api/coupons/quote', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    })->with([
        'no code' => [['items' => [['variant_id' => 1, 'quantity' => 1]]], 'code'],
        'no items' => [['code' => 'SAVE10'], 'items'],
        'empty items' => [['code' => 'SAVE10', 'items' => []], 'items'],
        'quantity zero' => [['code' => 'SAVE10', 'items' => [['variant_id' => 1, 'quantity' => 0]]], 'items.0.quantity'],
        'quantity too big' => [['code' => 'SAVE10', 'items' => [['variant_id' => 1, 'quantity' => 1001]]], 'items.0.quantity'],
        'bad variant' => [['code' => 'SAVE10', 'items' => [['variant_id' => 'abc', 'quantity' => 1]]], 'items.0.variant_id'],
        'duplicate variant' => [['code' => 'SAVE10', 'items' => [['variant_id' => 1, 'quantity' => 1], ['variant_id' => 1, 'quantity' => 2]]], 'items.0.variant_id'],
    ]);

    it('rejects more than 50 lines', function () {
        $items = array_map(fn ($i) => ['variant_id' => $i, 'quantity' => 1], range(1, 51));

        $this->postJson('/api/coupons/quote', ['code' => 'SAVE10', 'items' => $items])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    });

    it('limits quote requests to 20 per minute', function () {
        $variant = buyable(1000);
        makeCoupon(['code' => 'SAVE10', 'usage_limit_per_user' => null]);
        $body = ['code' => 'SAVE10', 'items' => cartOf([[$variant, 1]])];

        foreach (range(1, 20) as $i) {
            $this->postJson('/api/coupons/quote', $body)->assertOk();
        }

        $this->postJson('/api/coupons/quote', $body)->assertStatus(429);
    });
});
