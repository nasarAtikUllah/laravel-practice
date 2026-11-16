<?php

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreShippingOptionRequest extends FormRequest
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
            $this->merge(['code' => strtolower(trim($this->input('code')))]);
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
            // unique also counts deleted options, so a code is never reused
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'unique:shipping_options,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'estimated_days' => ['nullable', 'string', 'max:50'],
            'fee' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999'],
            'min_order_amount' => [
                'nullable',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:99999999',
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value !== null && $this->boolean('is_default')) {
                        $fail('The default delivery option cannot have a minimum order, because it must always be available.');
                    }
                },
            ],
            'is_default' => [
                'sometimes',
                'boolean',
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($this->boolean('is_default') && $this->has('is_active') && ! $this->boolean('is_active')) {
                        $fail('The default delivery option must be active.');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This code is already used, or was used by a deleted delivery option.',
            'code.regex' => 'The code can only contain small letters, numbers, dashes and underscores.',
        ];
    }
}
