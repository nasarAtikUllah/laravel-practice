<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreBrandRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:25', 'unique:brands,name'],
            'slug' => ['required', 'string', 'max:25', 'unique:brands,slug'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
