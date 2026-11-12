<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCouponRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // unique also counts deleted coupons, so an old code is never reused
            'code' => ['required', 'string', 'min:4', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', 'unique:coupons,code'],
            'type' => ['required', Rule::enum(CouponType::class)],
            'scope' => ['sometimes', Rule::enum(CouponScope::class)],
            'brand_ids' => [
                Rule::requiredIf(fn () => $this->input('scope') === CouponScope::Brands->value),
                Rule::prohibitedIf(fn () => $this->input('scope', CouponScope::All->value) !== CouponScope::Brands->value),
                'array',
                'max:100',
            ],
            'brand_ids.*' => ['integer', 'distinct', Rule::exists('brands', 'id')],
            'product_ids' => [
                Rule::requiredIf(fn () => $this->input('scope') === CouponScope::Products->value),
                Rule::prohibitedIf(fn () => $this->input('scope', CouponScope::All->value) !== CouponScope::Products->value),
                'array',
                'max:100',
            ],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'value' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:9999999999',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($this->input('type') === CouponType::Percentage->value && (float) $value > 100) {
                        $fail('A percentage cannot be more than 100.');
                    }
                },
            ],
            'max_discount' => [
                'nullable',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:9999999999',
                Rule::prohibitedIf(fn () => $this->input('type') === CouponType::Fixed->value),
            ],
            'min_order_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', Rule::when($this->filled('starts_at'), ['after:starts_at'])],
            'usage_limit_total' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'usage_limit_per_user' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This coupon code is already used, or was used by a deleted coupon.',
            'code.regex' => 'The code can only contain letters, numbers, dashes and underscores.',
            'max_discount.prohibited' => 'A maximum discount only works with a percentage coupon.',
            'expires_at.after' => 'The expiry time must be after the start time.',
            'brand_ids.required' => 'Select at least one brand for a brand coupon.',
            'brand_ids.prohibited' => 'Brands can only be set when the scope is brands.',
            'brand_ids.*.exists' => 'One of the selected brands does not exist.',
            'product_ids.required' => 'Select at least one product for a product coupon.',
            'product_ids.prohibited' => 'Products can only be set when the scope is products.',
            'product_ids.*.exists' => 'One of the selected products does not exist.',
        ];
    }
}
