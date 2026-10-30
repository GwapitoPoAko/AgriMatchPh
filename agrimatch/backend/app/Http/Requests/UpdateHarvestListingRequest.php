<?php

namespace App\Http\Requests;

use App\Models\HarvestListing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateHarvestListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('harvestListing'));
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'description', 'municipality', 'barangay'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim(strip_tags($this->input($key)))]);
            }
        }
        if ($this->has('latitude') && ! $this->has('pickup_latitude')) {
            $this->merge(['pickup_latitude' => $this->input('latitude')]);
        }
        if ($this->has('longitude') && ! $this->has('pickup_longitude')) {
            $this->merge(['pickup_longitude' => $this->input('longitude')]);
        }
    }

    public function rules(): array
    {
        /** @var HarvestListing $listing */
        $listing = $this->route('harvestListing');

        return [
            'province_id' => ['sometimes', 'integer', 'exists:provinces,id'],
            'municipality' => ['sometimes', 'string', 'max:120'],
            'barangay' => ['sometimes', 'string', 'max:120'],
            'title' => ['sometimes', 'string', 'min:8', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'quality_grade' => ['sometimes', Rule::in(['A', 'B', 'C'])],
            'is_organic' => ['sometimes', 'boolean'],
            'stock_volume_kg' => ['sometimes', 'numeric', 'min:50', 'max:10000000'],
            'available_volume_kg' => ['sometimes', 'numeric', 'min:0', 'lte:' . ($this->input('stock_volume_kg', $listing->stock_volume_kg))],
            'minimum_order_kg' => ['sometimes', 'numeric', 'min:0'],
            'unit_price' => ['sometimes', 'numeric', 'min:0.5', 'max:100000', 'decimal:0,2'],
            'price_negotiable' => ['sometimes', 'boolean'],
            'harvest_date_start' => ['sometimes', 'date_format:Y-m-d'],
            'harvest_date_end' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:' . ($this->input('harvest_date_start', $listing->harvest_date_start?->toDateString()))],
            'status' => ['sometimes', Rule::in([
                HarvestListing::STATUS_DRAFT, HarvestListing::STATUS_UPCOMING, HarvestListing::STATUS_ACTIVE,
                HarvestListing::STATUS_SOLD_OUT, HarvestListing::STATUS_EXPIRED,
            ])],
            'pickup_latitude' => ['sometimes', 'nullable', 'numeric', 'between:4.5,21.5'],
            'pickup_longitude' => ['sometimes', 'nullable', 'numeric', 'between:116,127'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:4.5,21.5'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:116,127'],
            'image_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            // Verification and grades come only from the inspection workflow.
            'is_verified' => ['prohibited'],
            'certified_grade' => ['prohibited'],
            'seller_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var HarvestListing $listing */
                $listing = $this->route('harvestListing');
                $committed = max(0, $listing->stock_volume_kg - $listing->available_volume_kg);
                if ($this->has('stock_volume_kg') && (float) $this->input('stock_volume_kg') < $committed) {
                    $validator->errors()->add('stock_volume_kg',
                        sprintf('%s kg is already contracted or pooled; stock cannot go below that.', number_format($committed)));
                }
            },
        ];
    }
}
