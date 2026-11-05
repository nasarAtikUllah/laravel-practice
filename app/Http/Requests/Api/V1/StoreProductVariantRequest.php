<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductVariantRequest extends FormRequest
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
        return [
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_variants', 'title')
                    ->where('product_id', $this->route('product')->id),
            ],
            'sku' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', 'unique:product_variants,sku'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'compare_price' => ['nullable', 'numeric', 'decimal:0,2', 'gt:price', 'max:9999999999'],
            'stock' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'This SKU is already used by another variant.',
            'sku.regex' => 'The SKU can only contain letters, numbers, dots, dashes and underscores.',
            'price.gt' => 'The price must be greater than 0.',
            'compare_price.gt' => 'The compare price must be greater than the selling price.',
        ];
    }
}
