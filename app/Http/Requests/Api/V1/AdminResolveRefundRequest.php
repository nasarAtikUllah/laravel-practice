<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\RefundStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminResolveRefundRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([RefundStatus::Succeeded->value, RefundStatus::Failed->value])],
            'provider_refund_id' => ['nullable', 'string', 'max:100'],
            'failure_reason' => ['required_if:status,failed', 'nullable', 'string', 'max:255'],
            'resolution_note' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
