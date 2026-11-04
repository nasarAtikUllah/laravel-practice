<?php

use App\Enums\ReservationStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ReservationNotActiveException;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function stockVariant(int $stock = 10, int $reserved = 0): ProductVariant
{
    return ProductVariant::factory()->create(['stock' => $stock, 'reserved' => $reserved]);
}

beforeEach(function () {
    $this->inventory = app(InventoryService::class);
});

// ---------- Manual changes ----------

it('writes the first movement for initial stock', function () {
    $variant = stockVariant(0);

    $movement = $this->inventory->initial($variant, 25);

    expect($variant->fresh()->stock)->toBe(25);
    expect($movement->type)->toBe(StockMovementType::Initial);
    expect($movement->quantity)->toBe(25);
    expect($movement->stock_before)->toBe(0);
    expect($movement->stock_after)->toBe(25);
});

it('writes nothing for an initial stock of zero', function () {
    $variant = stockVariant(0);

    expect($this->inventory->initial($variant, 0))->toBeNull();
    expect(InventoryMovement::count())->toBe(0);
});

it('adds stock on restock and records before and after', function () {
    $variant = stockVariant(10);

    $movement = $this->inventory->restock($variant, 5, 'Supplier delivery');

    expect($variant->fresh()->stock)->toBe(15);
    expect($movement->type)->toBe(StockMovementType::Restock);
    expect($movement->quantity)->toBe(5);
    expect($movement->stock_before)->toBe(10);
    expect($movement->stock_after)->toBe(15);
    expect($movement->reason)->toBe('Supplier delivery');
});

it('removes stock on damage', function () {
    $variant = stockVariant(10);

    $movement = $this->inventory->damage($variant, 3, 'Broken in storage');

    expect($variant->fresh()->stock)->toBe(7);
    expect($movement->quantity)->toBe(-3);
    expect($movement->type)->toBe(StockMovementType::Damage);
});

it('blocks removing more than the stock', function () {
    $variant = stockVariant(5);

    expect(fn () => $this->inventory->damage($variant, 6, 'Broken'))
        ->toThrow(InsufficientStockException::class);

    expect($variant->fresh()->stock)->toBe(5);
    expect(InventoryMovement::count())->toBe(0);
});

it('rejects a quantity of zero or less', function (int $quantity) {
    $variant = stockVariant(5);

    expect(fn () => $this->inventory->restock($variant, $quantity))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

it('saves who made the change', function () {
    $admin = User::factory()->create();

    $movement = $this->inventory->restock(stockVariant(1), 1, null, $admin);

    expect($movement->created_by)->toBe($admin->id);
});

it('does not allow a movement to be changed or deleted', function () {
    $movement = $this->inventory->restock(stockVariant(1), 1);

    expect(fn () => $movement->update(['reason' => 'x']))->toThrow(LogicException::class);
    expect(fn () => $movement->delete())->toThrow(LogicException::class);
});

// ---------- Stock count ----------

it('raises the stock to a higher counted number', function () {
    $variant = stockVariant(10);

    $movement = $this->inventory->countTo($variant, 12);

    expect($variant->fresh()->stock)->toBe(12);
    expect($movement->type)->toBe(StockMovementType::Correction);
    expect($movement->quantity)->toBe(2);
});

it('lowers the stock to a lower counted number', function () {
    $variant = stockVariant(10);

    $movement = $this->inventory->countTo($variant, 7);

    expect($variant->fresh()->stock)->toBe(7);
    expect($movement->quantity)->toBe(-3);
});

it('writes nothing when the count matches the stock', function () {
    $variant = stockVariant(10);

    expect($this->inventory->countTo($variant, 10))->toBeNull();
    expect(InventoryMovement::count())->toBe(0);
});

it('blocks a count below the reserved amount', function () {
    $variant = stockVariant(10, 4);

    expect(fn () => $this->inventory->countTo($variant, 3))
        ->toThrow(InsufficientStockException::class);

    expect($variant->fresh()->stock)->toBe(10);
});

// ---------- Sales, cancellations, returns ----------

it('deducts a sale and saves the reference', function () {
    $variant = stockVariant(10);

    $movement = $this->inventory->sell($variant, 2, 'order', 7);

    expect($variant->fresh()->stock)->toBe(8);
    expect($movement->type)->toBe(StockMovementType::Sale);
    expect($movement->reference_type)->toBe('order');
    expect($movement->reference_id)->toBe(7);
});

it('deducts a sale only once for the same order', function () {
    $variant = stockVariant(10);

    $first = $this->inventory->sell($variant, 2, 'order', 7);
    $second = $this->inventory->sell($variant, 2, 'order', 7);

    expect($second->id)->toBe($first->id);
    expect($variant->fresh()->stock)->toBe(8);
    expect(InventoryMovement::count())->toBe(1);
});

it('blocks a sale that would use stock held for other orders', function () {
    $variant = stockVariant(5, 3);

    expect(fn () => $this->inventory->sell($variant, 3, 'order', 1))
        ->toThrow(InsufficientStockException::class);

    $this->inventory->sell($variant, 2, 'order', 2);

    expect($variant->fresh()->stock)->toBe(3);
});

it('puts stock back when an order is cancelled', function () {
    $variant = stockVariant(10);
    $this->inventory->sell($variant, 2, 'order', 7);

    $movement = $this->inventory->restoreFromCancellation($variant, 2, 'order', 7);

    expect($variant->fresh()->stock)->toBe(10);
    expect($movement->type)->toBe(StockMovementType::Cancellation);
});

it('does not take stock from a deleted variant but accepts stock back', function () {
    $variant = stockVariant(5);
    $variant->delete();

    expect(fn () => $this->inventory->sell($variant, 1, 'order', 1))
        ->toThrow(ModelNotFoundException::class);

    $this->inventory->restoreFromCancellation($variant, 1, 'order', 2);

    expect(ProductVariant::withTrashed()->find($variant->id)->stock)->toBe(6);
});

// ---------- Reservations ----------

it('holds stock for 15 minutes', function () {
    $variant = stockVariant(10);

    $reservation = $this->inventory->reserve($variant, 4, 'order', 1);

    $fresh = $variant->fresh();

    expect($fresh->stock)->toBe(10);
    expect($fresh->reserved)->toBe(4);
    expect($fresh->available)->toBe(6);
    expect($reservation->status)->toBe(ReservationStatus::Active);
    expect((int) now()->diffInMinutes($reservation->expires_at, false))->toBeBetween(14, 15);
});

it('blocks a reservation that is bigger than the available stock', function () {
    $variant = stockVariant(5);

    expect(fn () => $this->inventory->reserve($variant, 6, 'order', 1))
        ->toThrow(InsufficientStockException::class);

    expect($variant->fresh()->reserved)->toBe(0);
});

it('does not let two reservations hold more than the stock', function () {
    $variant = stockVariant(5);
    $this->inventory->reserve($variant, 3, 'order', 1);

    expect(fn () => $this->inventory->reserve($variant, 3, 'order', 2))
        ->toThrow(InsufficientStockException::class);

    expect($variant->fresh()->reserved)->toBe(3);
});

it('creates one reservation when the same order reserves twice', function () {
    $variant = stockVariant(10);

    $first = $this->inventory->reserve($variant, 4, 'order', 1);
    $second = $this->inventory->reserve($variant, 4, 'order', 1);

    expect($second->id)->toBe($first->id);
    expect($variant->fresh()->reserved)->toBe(4);
});

it('turns a reservation into a sale on commit', function () {
    $variant = stockVariant(10);
    $reservation = $this->inventory->reserve($variant, 4, 'order', 1);

    $movement = $this->inventory->commitReservation($reservation);

    $fresh = $variant->fresh();

    expect($fresh->stock)->toBe(6);
    expect($fresh->reserved)->toBe(0);
    expect($fresh->available)->toBe(6);
    expect($movement->type)->toBe(StockMovementType::Sale);
    expect($reservation->fresh()->status)->toBe(ReservationStatus::Committed);
});

it('commits a reservation only once', function () {
    $variant = stockVariant(10);
    $reservation = $this->inventory->reserve($variant, 4, 'order', 1);

    $first = $this->inventory->commitReservation($reservation);
    $second = $this->inventory->commitReservation($reservation);

    expect($second->id)->toBe($first->id);
    expect($variant->fresh()->stock)->toBe(6);
    expect(InventoryMovement::count())->toBe(1);
});

it('refuses to commit a reservation that was released', function () {
    $variant = stockVariant(10);
    $reservation = $this->inventory->reserve($variant, 4, 'order', 1);
    $this->inventory->releaseReservation($reservation);

    expect(fn () => $this->inventory->commitReservation($reservation))
        ->toThrow(ReservationNotActiveException::class);

    expect($variant->fresh()->stock)->toBe(10);
});

it('gives the stock back when a reservation is released', function () {
    $variant = stockVariant(10);
    $reservation = $this->inventory->reserve($variant, 4, 'order', 1);

    expect($this->inventory->releaseReservation($reservation))->toBeTrue();

    expect($variant->fresh()->reserved)->toBe(0);
    expect($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});

it('releases a reservation only once', function () {
    $variant = stockVariant(10);
    $reservation = $this->inventory->reserve($variant, 4, 'order', 1);

    $this->inventory->releaseReservation($reservation);

    expect($this->inventory->releaseReservation($reservation))->toBeFalse();
    expect($variant->fresh()->reserved)->toBe(0);
});

it('lets the same order reserve again after its hold was released', function () {
    $variant = stockVariant(5);
    $reservation = $this->inventory->reserve($variant, 3, 'order', 1);
    $this->inventory->releaseReservation($reservation);

    $again = $this->inventory->reserve($variant, 3, 'order', 1);

    expect($again->id)->toBe($reservation->id);
    expect($again->status)->toBe(ReservationStatus::Active);
    expect($variant->fresh()->reserved)->toBe(3);
});

it('releases only the reservations that have expired', function () {
    $variant = stockVariant(10);
    $old = $this->inventory->reserve($variant, 2, 'order', 1);

    $this->travel(10)->minutes();
    $this->inventory->reserve($variant, 3, 'order', 2);

    $this->travel(6)->minutes(); // the first hold is now 16 minutes old

    expect($this->inventory->releaseExpired())->toBe(1);
    expect($old->fresh()->status)->toBe(ReservationStatus::Expired);
    expect($variant->fresh()->reserved)->toBe(3);
});

it('keeps reservations that are still within 15 minutes', function () {
    $variant = stockVariant(10);
    $this->inventory->reserve($variant, 2, 'order', 1);

    $this->travel(14)->minutes();

    expect($this->inventory->releaseExpired())->toBe(0);
    expect($variant->fresh()->reserved)->toBe(2);
});

it('runs the expiry command', function () {
    $variant = stockVariant(10);
    $this->inventory->reserve($variant, 2, 'order', 1);
    $this->travel(16)->minutes();

    $this->artisan('inventory:release-expired')
        ->expectsOutput('Released 1 expired reservation(s).')
        ->assertSuccessful();

    expect($variant->fresh()->reserved)->toBe(0);
});

it('never shows a negative available quantity', function () {
    expect(stockVariant(5, 3)->available)->toBe(2);
    expect(stockVariant(2, 5)->available)->toBe(0);
});

// ---------- Configuration ----------

it('reads the reservation minutes from the config', function () {
    config(['inventory.reservation_minutes' => 30]);

    $reservation = $this->inventory->reserve(stockVariant(10), 1, 'order', 1);

    expect((int) now()->diffInMinutes($reservation->expires_at, false))->toBeBetween(29, 30);
});

it('uses the default reservation time when the config value is not valid', function (mixed $value) {
    config(['inventory.reservation_minutes' => $value]);

    expect(InventoryService::reservationMinutes())->toBe(15);
})->with([0, -5, null]);

it('reads the cod auto-cancel hours from the config', function () {
    expect(InventoryService::codAutoCancelHours())->toBe(24);

    config(['inventory.cod_auto_cancel_hours' => 48]);

    expect(InventoryService::codAutoCancelHours())->toBe(48);

    config(['inventory.cod_auto_cancel_hours' => 0]);

    expect(InventoryService::codAutoCancelHours())->toBe(24);
});

// ---------- Cancelling an order (before shipping) ----------

it('restores every line of a cancelled order', function () {
    $a = stockVariant(10);
    $b = stockVariant(20);
    $this->inventory->sell($a, 2, 'order', 5);
    $this->inventory->sell($b, 3, 'order', 5);

    $restored = $this->inventory->restoreStockForOrder('order', 5, 'Customer cancelled');

    expect($restored)->toBe(2);
    expect($a->fresh()->stock)->toBe(10);
    expect($b->fresh()->stock)->toBe(20);
    expect(InventoryMovement::where('type', 'cancellation')->count())->toBe(2);
});

it('restores the stock of an order only once', function () {
    $variant = stockVariant(10);
    $this->inventory->sell($variant, 2, 'order', 5);

    $this->inventory->restoreStockForOrder('order', 5);
    $second = $this->inventory->restoreStockForOrder('order', 5);

    expect($second)->toBe(0);
    expect($variant->fresh()->stock)->toBe(10);
    expect(InventoryMovement::where('type', 'cancellation')->count())->toBe(1);
});

it('restores nothing when no stock was sold for the order', function () {
    stockVariant(10);

    expect($this->inventory->restoreStockForOrder('order', 99))->toBe(0);
    expect(InventoryMovement::count())->toBe(0);
});

it('does not touch the stock of other orders', function () {
    $variant = stockVariant(10);
    $this->inventory->sell($variant, 2, 'order', 5);
    $this->inventory->sell($variant, 3, 'order', 6);

    $this->inventory->restoreStockForOrder('order', 5);

    expect($variant->fresh()->stock)->toBe(7);
});

it('saves the reason and the user of a cancellation', function () {
    $user = User::factory()->staff()->create();
    $this->inventory->sell(stockVariant(10), 2, 'order', 5);

    $this->inventory->restoreStockForOrder('order', 5, 'Not confirmed within 24 hours', $user);

    $movement = InventoryMovement::where('type', 'cancellation')->firstOrFail();

    expect($movement->reason)->toBe('Not confirmed within 24 hours');
    expect($movement->created_by)->toBe($user->id);
});

it('restores the stock of a cancelled order to a deleted variant', function () {
    $variant = stockVariant(10);
    $this->inventory->sell($variant, 2, 'order', 5);
    $variant->delete();

    $this->inventory->restoreStockForOrder('order', 5);

    expect(ProductVariant::withTrashed()->find($variant->id)->stock)->toBe(10);
});

// ---------- Returns (after delivery) ----------

it('puts a sellable return back into stock when an admin receives it', function () {
    $variant = stockVariant(5);
    $admin = User::factory()->admin()->create();

    $this->inventory->restoreFromReturn($variant, 2, 'return', 9, $admin);

    expect($variant->fresh()->stock)->toBe(7);
});

it('puts a sellable return back into stock when inventory staff receives it', function () {
    $variant = stockVariant(5);
    $staff = User::factory()->staff()->create();

    $movement = $this->inventory->restoreFromReturn($variant, 2, 'return', 9, $staff);

    expect($variant->fresh()->stock)->toBe(7);
    expect($movement->created_by)->toBe($staff->id);
});

it('refuses a return confirmed by a customer', function () {
    $variant = stockVariant(5);
    $customer = User::factory()->create();

    expect(fn () => $this->inventory->restoreFromReturn($variant, 2, 'return', 9, $customer))
        ->toThrow(AuthorizationException::class);

    expect($variant->fresh()->stock)->toBe(5);
    expect(InventoryMovement::count())->toBe(0);
});

it('does not add sellable stock for a damaged return and records the damage', function () {
    $variant = stockVariant(5);
    $staff = User::factory()->staff()->create();

    $this->inventory->restoreFromReturn($variant, 2, 'return', 9, $staff, sellable: false);

    $movements = InventoryMovement::orderBy('id')->get();

    expect($variant->fresh()->stock)->toBe(5);
    expect($movements->map(fn ($m) => $m->type->value)->all())->toBe(['return', 'damage']);
    expect($movements->last()->quantity)->toBe(-2);
    expect($movements->last()->reason)->toBe('Returned item is not sellable');
});

it('restores a return only once', function () {
    $variant = stockVariant(5);
    $staff = User::factory()->staff()->create();

    $first = $this->inventory->restoreFromReturn($variant, 2, 'return', 9, $staff);
    $second = $this->inventory->restoreFromReturn($variant, 2, 'return', 9, $staff);

    expect($second->id)->toBe($first->id);
    expect($variant->fresh()->stock)->toBe(7);
});
