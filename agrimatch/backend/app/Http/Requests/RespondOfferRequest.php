<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RespondOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $offer = $this->route('offer');
        $ability = $this->input('action') === 'withdraw' ? 'withdraw' : 'respond';

        return (bool) $this->user()?->can($ability, $offer);
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['accept', 'reject', 'counter', 'withdraw'])],
            'unit_price' => ['required_if:action,counter', 'nullable', 'numeric', 'min:0.5', 'max:100000', 'decimal:0,2'],
            'volume_kg' => ['nullable', 'numeric', 'min:1', 'max:10000000'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim(strip_tags($this->input('message')))]);
        }
    }
}
