<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ShippingOption;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateShippingOptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'estimated_days' => ['sometimes', 'nullable', 'string', 'max:50'],
            'fee' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999'],
            'min_order_amount' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.prohibited' => 'The code cannot be changed. Create a new delivery option instead.',
        ];
    }

    /** Rules that depend on the saved values and on the other options. */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var ShippingOption $option */
            $option = $this->route('shippingOption');

            $default = $this->has('is_default') ? $this->boolean('is_default') : $option->is_default;
            $active = $this->has('is_active') ? $this->boolean('is_active') : $option->is_active;
            $minimum = $this->has('min_order_amount') ? $this->input('min_order_amount') : $option->min_order_amount;

            if ($default && ! $active) {
                $validator->errors()->add('is_active', 'The default delivery option must be active.');
            }

            if ($default && $minimum !== null && $minimum !== '') {
                $validator->errors()->add('min_order_amount', 'The default delivery option cannot have a minimum order, because it must always be available.');
            }

            if ($option->is_default && $this->has('is_default') && ! $this->boolean('is_default')) {
                $validator->errors()->add('is_default', 'There must always be a default delivery option. Make another option the default instead.');
            }

            if ($option->is_active && $this->has('is_active') && ! $this->boolean('is_active')
                && ! ShippingOption::query()->active()->whereKeyNot($option->id)->exists()) {
                $validator->errors()->add('is_active', 'At least one delivery option must stay active.');
            }
        }];
    }
}
