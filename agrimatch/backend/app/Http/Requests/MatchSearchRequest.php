<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MatchSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'crop_variety_id' => ['required', 'integer', 'exists:crop_varieties,id'],
            'delivery_province_id' => ['required', 'integer', 'exists:provinces,id'],
            'quantity_kg' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'needed_from' => ['nullable', 'date'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:needed_from'],
            'max_unit_price' => ['nullable', 'numeric', 'min:0.5'],
            'max_distance_km' => ['nullable', 'integer', 'min:10', 'max:2000'],
            'min_quality_grade' => ['nullable', Rule::in(['A', 'B', 'C'])],
            'include_substitutes' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
