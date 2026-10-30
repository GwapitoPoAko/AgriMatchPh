<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Hakot calculator input. Either reference one of your listings (pickup point
 * and volume default from it) or give a raw pickup point + volume.
 */
class FreightQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trading_hub_id' => ['required', 'integer', 'exists:trading_hubs,id'],
            'harvest_listing_id' => ['nullable', 'integer', 'exists:harvest_listings,id'],
            'volume_kg' => ['required_without:harvest_listing_id', 'nullable', 'numeric', 'min:50', 'max:200000'],
            'pickup_latitude' => ['required_without:harvest_listing_id', 'nullable', 'numeric', 'between:4.5,21.5'],
            'pickup_longitude' => ['required_without:harvest_listing_id', 'nullable', 'numeric', 'between:116,127'],
            'barangay' => ['nullable', 'string', 'max:120'],
            'departure_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:' . now()->addDays(90)->toDateString()],
        ];
    }
}
