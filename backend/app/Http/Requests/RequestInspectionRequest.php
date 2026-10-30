<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('requestInspection', $this->route('harvestListing'));
    }

    public function rules(): array
    {
        return [
            'preferred_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:' . now()->addDays(60)->toDateString()],
            'claimed_grade' => ['nullable', 'in:export,grade_a,class_b,class_c'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
