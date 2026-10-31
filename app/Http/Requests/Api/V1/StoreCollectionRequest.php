<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCollectionRequest extends FormRequest
{
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

    public function rules(): array
    {
        $brand = $this->route('brand');

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('collections', 'name')->where('brand_id', $brand->id),
            ],
            'slug' => [
                'required', 'string', 'max:255',
                Rule::unique('collections', 'slug')->where('brand_id', $brand->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
