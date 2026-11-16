<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddCartItemRequest;
use App\Http\Requests\Api\V1\ApplyCartCouponRequest;
use App\Http\Requests\Api\V1\SelectShippingRequest;
use App\Http\Requests\Api\V1\UpdateCartItemRequest;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function show(Request $request): JsonResponse
    {
        return $this->success($this->cart->view($this->user($request)), 'Cart retrieved successfully.');
    }

    public function clear(Request $request): JsonResponse
    {
        return $this->success($this->cart->clear($this->user($request)), 'Cart cleared successfully.');
    }

    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        $data = $this->cart->addItem(
            $this->user($request),
            $request->integer('variant_id'),
            $request->integer('quantity')
        );

        return $this->success($data, 'Item added to cart.', 201);
    }

    public function updateItem(UpdateCartItemRequest $request, int $variantId): JsonResponse
    {
        $data = $this->cart->setQuantity($this->user($request), $variantId, $request->integer('quantity'));

        return $this->success($data, 'Cart item updated.');
    }

    public function removeItem(Request $request, int $variantId): JsonResponse
    {
        return $this->success($this->cart->removeItem($this->user($request), $variantId), 'Item removed from cart.');
    }

    public function applyCoupon(ApplyCartCouponRequest $request): JsonResponse
    {
        $data = $this->cart->applyCoupon($this->user($request), (string) $request->input('code'));

        return $this->success($data, 'Coupon applied to your cart.');
    }

    public function removeCoupon(Request $request): JsonResponse
    {
        return $this->success($this->cart->removeCoupon($this->user($request)), 'Coupon removed from your cart.');
    }

    public function prepare(Request $request): JsonResponse
    {
        $data = $this->cart->prepare($this->user($request));

        if (! $data['ready']) {
            return $this->error('Your cart needs attention before checkout.', 422, $data['issues']);
        }

        return $this->success($data, 'Cart is ready for checkout.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    public function selectShipping(SelectShippingRequest $request): JsonResponse
    {
        $data = $this->cart->selectShipping($this->user($request), $request->integer('shipping_option_id'));

        return $this->success($data, 'Delivery option selected.');
    }
}
