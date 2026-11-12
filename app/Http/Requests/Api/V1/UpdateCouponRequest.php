<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class UpdateCouponRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $coupon = $this->route('coupon');
        $type = fn (): string => (string) $this->input('type', $coupon->type->value);
        $value = fn () => $this->input('value', $coupon->value);
        $scope = fn (): string => (string) $this->input('scope', $coupon->scope->value);

        return [
            'code' => ['prohibited'],
            'type' => [
                'sometimes', 'required', Rule::enum(CouponType::class),
                function (string $attribute, mixed $given, Closure $fail) use ($value) {
                    if ($given === CouponType::Percentage->value && (float) $value() > 100) {
                        $fail('The saved value is more than 100. Send a value of 100 or less together with the new type.');
                    }
                },
            ],
            'scope' => ['sometimes', 'required', Rule::enum(CouponScope::class)],
            'brand_ids' => [
                Rule::requiredIf(fn () => $scope() === CouponScope::Brands->value && $coupon->scope !== CouponScope::Brands),
                'array', 'max:100',
                function (string $attribute, mixed $given, Closure $fail) use ($scope) {
                    if ($scope() !== CouponScope::Brands->value && ! empty($given)) {
                        $fail('Brands can only be set when the scope is brands.');
                    }

                    if ($scope() === CouponScope::Brands->value && $given === []) {
                        $fail('Select at least one brand.');
                    }
                },
            ],
            'brand_ids.*' => ['integer', 'distinct', Rule::exists('brands', 'id')],
            'product_ids' => [
                Rule::requiredIf(fn () => $scope() === CouponScope::Products->value && $coupon->scope !== CouponScope::Products),
                'array', 'max:100',
                function (string $attribute, mixed $given, Closure $fail) use ($scope) {
                    if ($scope() !== CouponScope::Products->value && ! empty($given)) {
                        $fail('Products can only be set when the scope is products.');
                    }

                    if ($scope() === CouponScope::Products->value && $given === []) {
                        $fail('Select at least one product.');
                    }
                },
            ],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'value' => [
                'sometimes', 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999',
                function (string $attribute, mixed $given, Closure $fail) use ($type) {
                    if ($type() === CouponType::Percentage->value && (float) $given > 100) {
                        $fail('A percentage cannot be more than 100.');
                    }
                },
            ],
            'max_discount' => [
                'sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999',
                function (string $attribute, mixed $given, Closure $fail) use ($type) {
                    if ($given !== null && $type() === CouponType::Fixed->value) {
                        $fail('A maximum discount only works with a percentage coupon.');
                    }
                },
            ],
            'min_order_amount' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'starts_at' => [
                'sometimes', 'nullable', 'date',
                function (string $attribute, mixed $given, Closure $fail) use ($coupon) {
                    $end = $this->has('expires_at') ? $this->input('expires_at') : $coupon->expires_at;

                    if ($given !== null && $end !== null && Carbon::parse($end)->lte(Carbon::parse($given))) {
                        $fail('The start time must be before the expiry time.');
                    }
                },
            ],
            'expires_at' => [
                'sometimes', 'nullable', 'date',
                function (string $attribute, mixed $given, Closure $fail) use ($coupon) {
                    $start = $this->has('starts_at') ? $this->input('starts_at') : $coupon->starts_at;

                    if ($given !== null && $start !== null && Carbon::parse($given)->lte(Carbon::parse($start))) {
                        $fail('The expiry time must be after the start time.');
                    }
                },
            ],
            'usage_limit_total' => [
                'sometimes', 'nullable', 'integer', 'min:1', 'max:100000000',
                function (string $attribute, mixed $given, Closure $fail) use ($coupon) {
                    $used = $coupon->usedCount();

                    if ($given !== null && (int) $given < $used) {
                        $fail("This coupon was already used {$used} times. The limit cannot be lower than that.");
                    }
                },
            ],
            'usage_limit_per_user' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.prohibited' => 'The coupon code cannot be changed. Create a new coupon instead.',
            'brand_ids.required' => 'Select at least one brand for a brand coupon.',
            'brand_ids.prohibited' => 'Brands can only be set when the scope is brands.',
            'brand_ids.*.exists' => 'One of the selected brands does not exist.',
            'product_ids.required' => 'Select at least one product for a product coupon.',
            'product_ids.prohibited' => 'Products can only be set when the scope is products.',
            'product_ids.*.exists' => 'One of the selected products does not exist.',
        ];
    }
}
