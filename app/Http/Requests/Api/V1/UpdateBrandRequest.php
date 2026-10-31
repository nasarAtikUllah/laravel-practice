<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateBrandRequest extends FormRequest
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
        $brand = $this->route('brand');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:25', Rule::unique('brands', 'name')->ignore($brand)],
            'slug' => ['sometimes', 'required', 'string', 'max:25', Rule::unique('brands', 'slug')->ignore($brand)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
