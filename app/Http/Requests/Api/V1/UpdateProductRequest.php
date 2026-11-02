<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductStatus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => Str::slug($this->input('slug'))]);
        }
    }

    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'brand_id' => ['sometimes', 'required', 'integer', Rule::exists('brands', 'id')->where('is_active', true)],
            'collection_id' => [
                'required_with:brand_id', 'integer',
                Rule::exists('collections', 'id')
                    ->where('brand_id', $this->input('brand_id', $product->brand_id))
                    ->where('is_active', true),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product)],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => [
                'sometimes', 'required', Rule::enum(ProductStatus::class),
                function (string $attribute, mixed $value, Closure $fail) use ($product) {
                    if ($value === ProductStatus::Published->value && ! $product->variants()->exists()) {
                        $fail('A product needs at least one variant before it can be published.');
                    }
                },
            ],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'brand_id.exists' => 'The selected brand does not exist or is inactive.',
            'collection_id.exists' => 'The selected collection does not exist, is inactive, or does not belong to this brand.',
            'collection_id.required_with' => 'Send collection_id together with brand_id.',
            'slug.unique' => 'This slug is already used by another product.',
        ];
    }
}
