<?php

namespace App\Http\Requests;

use App\Models\HarvestListing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a new harvest listing: volume, pricing, harvest window and the
 * quality-inspection trigger.
 *
 * Inspection rules:
 *  - claiming export grade (`claimed_grade = export`) REQUIRES an inspection,
 *  - lots ≥ config('agrimatch.inspection.mandatory_volume_kg') REQUIRE one,
 *  - otherwise `request_inspection` is optional.
 *
 * Only these keys reach the controller via validated(); anything else in the
 * payload (seller_id, is_verified, certified_grade, …) is discarded.
 */
class StoreHarvestListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', HarvestListing::class);
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['title', 'description', 'municipality', 'barangay'] as $key) {
            if (is_string($this->input($key))) {
                // Plain text only: strip tags and collapse control characters.
                $merge[$key] = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags($this->input($key))));
            }
        }
        foreach (['is_organic', 'price_negotiable', 'request_inspection'] as $key) {
            if ($this->has($key)) {
                $merge[$key] = filter_var($this->input($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
        }
        if ($this->has('latitude') && ! $this->has('pickup_latitude')) {
            $merge['pickup_latitude'] = $this->input('latitude');
        }
        if ($this->has('longitude') && ! $this->has('pickup_longitude')) {
            $merge['pickup_longitude'] = $this->input('longitude');
        }
        if (! $this->has('pickup_latitude') && ! $this->has('pickup_longitude')) {
            $profile = $this->user()?->profile;
            if ($profile?->latitude !== null && $profile?->longitude !== null) {
                $merge['pickup_latitude'] = $profile->latitude;
                $merge['pickup_longitude'] = $profile->longitude;
            }
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'crop_variety_id' => ['required', 'integer', Rule::exists('crop_varieties', 'id')->where('is_active', true)],
            'province_id' => ['required', 'integer', 'exists:provinces,id'],
            'municipality' => ['required', 'string', 'min:2', 'max:120'],
            'barangay' => ['required', 'string', 'min:2', 'max:120'],
            'title' => ['required', 'string', 'min:8', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'quality_grade' => ['required', Rule::in(['A', 'B', 'C'])],
            'claimed_grade' => ['nullable', Rule::in(['export', 'grade_a', 'class_b', 'class_c'])],
            'is_organic' => ['sometimes', 'boolean'],

            // Volume (kg) and pricing (PHP per kg)
            'stock_volume_kg' => ['required', 'numeric', 'min:50', 'max:10000000'],
            'minimum_order_kg' => ['nullable', 'numeric', 'min:0', 'lte:stock_volume_kg'],
            'unit_price' => ['required', 'numeric', 'min:0.5', 'max:100000', 'decimal:0,2'],
            'price_negotiable' => ['sometimes', 'boolean'],

            // Harvest window
            'harvest_date_start' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . now()->subDays(30)->toDateString(), 'before_or_equal:' . now()->addYear()->toDateString()],
            'harvest_date_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:harvest_date_start'],

            // Pickup point for logistics pooling (optional — falls back to the province centroid)
            'pickup_latitude' => ['nullable', 'numeric', 'between:4.5,21.5', 'required_with:pickup_longitude'],
            'pickup_longitude' => ['nullable', 'numeric', 'between:116,127', 'required_with:pickup_latitude'],
            'latitude' => ['nullable', 'numeric', 'between:4.5,21.5'],
            'longitude' => ['nullable', 'numeric', 'between:116,127'],

            // Quality inspection trigger
            'request_inspection' => ['sometimes', 'nullable', 'boolean'],
            'preferred_inspection_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'required_if:request_inspection,true'],

            'image_url' => ['nullable', 'url:https', 'max:500'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $volume = (float) $this->input('stock_volume_kg');
                $mandatoryVolume = (float) config('agrimatch.inspection.mandatory_volume_kg');
                $requested = (bool) $this->input('request_inspection');

                if ($this->input('claimed_grade') === 'export' && ! $requested) {
                    $validator->errors()->add('request_inspection', 'Export-grade claims must be verified: request a quality inspection.');
                }
                if ($volume >= $mandatoryVolume && ! $requested) {
                    $validator->errors()->add('request_inspection',
                        sprintf('Lots of %s kg or more require a quality inspection before going to market.', number_format($mandatoryVolume)));
                }

                $start = Carbon::parse($this->input('harvest_date_start'));
                $end = Carbon::parse($this->input('harvest_date_end'));
                if ($start->diffInDays($end) > 180) {
                    $validator->errors()->add('harvest_date_end', 'A harvest window cannot exceed 180 days. Split it into separate listings.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'harvest_date_start.after_or_equal' => 'Harvest start can be at most 30 days in the past.',
            'harvest_date_start.before_or_equal' => 'Harvest start must be within the next 12 months.',
            'minimum_order_kg.lte' => 'Minimum order cannot exceed total stock.',
            'unit_price.decimal' => 'Price can have at most 2 decimal places.',
            'preferred_inspection_date.required_if' => 'Pick a preferred inspection date.',
            'pickup_latitude.between' => 'Pickup point must be inside the Philippines.',
            'pickup_longitude.between' => 'Pickup point must be inside the Philippines.',
        ];
    }
}
