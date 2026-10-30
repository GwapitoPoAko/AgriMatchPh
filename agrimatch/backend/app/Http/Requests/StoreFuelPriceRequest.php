<?php

namespace App\Http\Requests;

use App\Models\FuelPriceLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFuelPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage-fuel-prices');
    }

    public function rules(): array
    {
        return [
            'region_code' => ['required', 'string', 'max:10', Rule::exists('provinces', 'region_code')],
            'fuel_type' => ['required', Rule::in(FuelPriceLog::FUEL_TYPES)],
            'effective_date' => [
                'required', 'date_format:Y-m-d', 'before_or_equal:' . now()->addDays(7)->toDateString(),
                Rule::unique('fuel_price_logs')->where(fn ($q) => $q->where('region_code', $this->input('region_code'))->where('fuel_type', $this->input('fuel_type'))),
            ],
            'price_per_liter' => ['required', 'numeric', 'min:20', 'max:200', 'decimal:0,2'],
            'source' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return ['effective_date.unique' => 'A price for this region, fuel and week is already logged.'];
    }
}
