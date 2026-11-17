<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PaymentMethod;
use App\Support\Districts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
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
        $merge = ['idempotency_key' => $this->header('Idempotency-Key')];

        if (is_string($this->input('shipping_phone'))) {
            $phone = (string) preg_replace('/[\s\-]/', '', $this->input('shipping_phone'));

            // +8801712345678 and 8801712345678 become 01712345678
            if (preg_match('/^\+?88(01[3-9]\d{8})$/', $phone, $match)) {
                $phone = $match[1];
            }

            $merge['shipping_phone'] = $phone;
        }

        if (is_string($this->input('shipping_district'))) {
            $merge['shipping_district'] = Districts::find($this->input('shipping_district')) ?? $this->input('shipping_district');
        }

        $this->merge($merge);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'cart_hash' => ['required', 'string', 'size:64'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'shipping_name' => ['required', 'string', 'min:2', 'max:100'],
            'shipping_phone' => ['required', 'string', 'regex:/^01[3-9]\d{8}$/'],
            'shipping_address' => ['required', 'string', 'min:10', 'max:500'],
            'shipping_district' => ['required', 'string', Rule::in(Districts::all())],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'idempotency_key.min' => 'The Idempotency-Key header must be at least 8 characters.',
            'idempotency_key.max' => 'The Idempotency-Key header must not be longer than 64 characters.',
            'idempotency_key.regex' => 'The Idempotency-Key header can only contain letters, numbers, dashes and underscores.',
            'cart_hash.size' => 'The cart hash must be the one returned by POST /api/cart/prepare.',
            'shipping_phone.regex' => 'Enter a valid Bangladesh mobile number, for example 01712345678.',
            'shipping_district.in' => 'Choose one of the 64 districts of Bangladesh.',
        ];
    }
}
