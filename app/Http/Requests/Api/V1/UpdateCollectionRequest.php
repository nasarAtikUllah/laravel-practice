<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateCollectionRequest extends FormRequest
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
        $collection = $this->route('collection');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('collections', 'name')
                    ->where('brand_id', $collection->brand_id)
                    ->ignore($collection),
            ],
            'slug' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('collections', 'slug')
                    ->where('brand_id', $collection->brand_id)
                    ->ignore($collection),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
