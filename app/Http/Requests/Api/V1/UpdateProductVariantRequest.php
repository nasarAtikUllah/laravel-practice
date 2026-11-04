<?php

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductVariantRequest extends FormRequest
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
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => strtoupper(trim($this->input('sku')))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $variant = $this->route('variant');

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => [
                'sometimes', 'required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('product_variants', 'sku')->ignore($variant),
            ],
            'price' => [
                'sometimes', 'required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999',
                function (string $attribute, mixed $value, Closure $fail) use ($variant) {
                    $compare = $this->has('compare_price') ? $this->input('compare_price') : $variant->compare_price;

                    if ($compare !== null && (float) $value >= (float) $compare) {
                        $fail('The selling price must be lower than the compare price. Update the compare price too.');
                    }
                },
            ],
            'compare_price' => [
                'sometimes', 'nullable', 'numeric', 'decimal:0,2', 'max:9999999999',
                function (string $attribute, mixed $value, Closure $fail) use ($variant) {
                    $price = $this->has('price') ? $this->input('price') : $variant->price;

                    if ($value !== null && (float) $value <= (float) $price) {
                        $fail('The compare price must be greater than the selling price.');
                    }
                },
            ],
            'stock' => ['prohibited'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'This SKU is already used by another variant.',
            'sku.regex' => 'The SKU can only contain letters, numbers, dots, dashes and underscores.',
            'price.gt' => 'The price must be greater than 0.',
            'stock.prohibited' => 'Stock cannot be changed here. Use the stock adjust or stock count endpoints.',
        ];
    }
}
