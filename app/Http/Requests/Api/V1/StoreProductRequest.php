<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
        $source = $this->input('slug') ?: $this->input('name');

        if (is_string($source)) {
            $this->merge(['slug' => Str::slug($source)]);
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
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')->where('is_active', true)],
            'collection_id' => [
                'required', 'integer',
                Rule::exists('collections', 'id')
                    ->where('brand_id', $this->input('brand_id'))
                    ->where('is_active', true),
            ],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:products,slug'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', Rule::in(['draft', 'upcoming'])],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'brand_id.exists' => 'The selected brand does not exist or is inactive.',
            'collection_id.exists' => 'The selected collection does not exist, is inactive, or does not belong to this brand.',
            'slug.unique' => 'This slug is already used by another product.',
            'status.in' => 'A new product can only be draft or upcoming. Add a variant first, then publish it.',
        ];
    }
}
