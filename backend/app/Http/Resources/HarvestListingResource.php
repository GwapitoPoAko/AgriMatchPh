<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\User;
use App\Support\Geo;

/**
 * @mixin \App\Models\HarvestListing
 *
 * Optional runtime attributes set by the controller (not columns):
 *  - market_signal  array  latest hub price-index signal ("+12% market spike")
 */
class HarvestListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sold = max(0, $this->stock_volume_kg - $this->available_volume_kg);
        $viewer = $request->user('sanctum');
        $canViewPickup = $viewer && ($viewer->isAdmin() || $viewer->id === $this->seller_id
            || in_array($viewer->role, User::BUYER_ROLES, true));
        $latitude = $this->pickup_latitude ?? $this->latitude;
        $longitude = $this->pickup_longitude ?? $this->longitude;

        return [
            'id' => $this->id,
            'reference_code' => $this->reference_code,
            'title' => $this->title,
            'description' => $this->description,
            'quality_grade' => $this->quality_grade,
            'is_organic' => $this->is_organic,
            'is_verified' => $this->is_verified,
            'status' => $this->status,
            'stock_volume_kg' => $this->stock_volume_kg,
            'available_volume_kg' => $this->available_volume_kg,
            'sold_pct' => $this->stock_volume_kg > 0 ? round($sold / $this->stock_volume_kg * 100, 1) : 0,
            'minimum_order_kg' => $this->minimum_order_kg,
            'unit_price' => $this->unit_price,
            'price_negotiable' => $this->price_negotiable,
            'total_value' => round($this->available_volume_kg * $this->unit_price, 2),
            'harvest_date_start' => $this->harvest_date_start?->toDateString(),
            'harvest_date_end' => $this->harvest_date_end?->toDateString(),
            'image_url' => $this->image_url,
            'views_count' => $this->views_count,
            'pending_offers_count' => $this->whenCounted('pendingOffers'),

            'quality' => [
                'certified_grade' => $this->certified_grade,
                'certified_label' => $this->certifiedGradeLabel(),
                'inspection_status' => $this->inspection_status,
                'latest_inspection' => $this->whenLoaded('latestInspection', fn () => $this->latestInspection ? [
                    'reference_code' => $this->latestInspection->reference_code,
                    'status' => $this->latestInspection->status,
                    'certificate_no' => $this->latestInspection->certificate_no,
                    'inspected_at' => $this->latestInspection->inspected_at?->toIso8601String(),
                    'valid_until' => $this->latestInspection->valid_until?->toDateString(),
                    'defect_pct' => $this->latestInspection->defect_pct,
                    'moisture_pct' => $this->latestInspection->moisture_pct,
                    'brix' => $this->latestInspection->brix,
                ] : null),
            ],

            'coop' => [
                'is_pool_parent' => $this->is_pool_parent,
                'is_pool_member' => $this->parent_listing_id !== null,
                'parent_listing_id' => $this->parent_listing_id,
                'pooled_volume_kg' => $this->pooled_volume_kg,
                'member_count' => $this->whenCounted('poolMembers'),
                'cooperative' => $this->whenLoaded('cooperative', fn () => $this->cooperative ? [
                    'id' => $this->cooperative->id,
                    'name' => $this->cooperative->name,
                ] : null),
            ],

            'market_signal' => $this->when($this->resource->getAttribute('market_signal') !== null, fn () => $this->resource->getAttribute('market_signal')),

            'location' => [
                'province_id' => $this->province_id,
                'province' => $this->whenLoaded('province', fn () => $this->province->name),
                'region' => $this->whenLoaded('province', fn () => $this->province->region),
                'municipality' => $this->municipality,
                'barangay' => $this->barangay,
                'trading_hub' => $this->whenLoaded('tradingHub', fn () => $this->tradingHub ? [
                    'id' => $this->tradingHub->id,
                    'short_name' => $this->tradingHub->short_name,
                ] : null),
                'has_pickup_point' => $latitude !== null && $longitude !== null,
                'farm_coordinates' => $this->when($canViewPickup && $latitude !== null && $longitude !== null, fn () => [
                    'latitude' => (float) $latitude,
                    'longitude' => (float) $longitude,
                ]),
                'distance_to_hub_km' => $latitude !== null && $longitude !== null && $this->relationLoaded('tradingHub') && $this->tradingHub
                    ? round(Geo::roadKm((float) $latitude, (float) $longitude, (float) $this->tradingHub->latitude, (float) $this->tradingHub->longitude), 1)
                    : null,
                'trading_hub_coordinates' => $this->when($this->relationLoaded('tradingHub') && $this->tradingHub, fn () => [
                    'latitude' => (float) $this->tradingHub->latitude,
                    'longitude' => (float) $this->tradingHub->longitude,
                ]),
            ],
            'crop' => $this->whenLoaded('variety', fn () => [
                'variety_id' => $this->variety->id,
                'variety' => $this->variety->name,
                'local_name' => $this->variety->local_name,
                'category_id' => $this->variety->crop_category_id,
                'category' => $this->variety->relationLoaded('category') ? $this->variety->category?->name : null,
                'shelf_life_days' => $this->variety->shelf_life_days,
            ]),
            'seller' => $this->whenLoaded('seller', fn () => [
                'id' => $this->seller->id,
                'name' => $this->seller->name,
                'role' => $this->seller->role,
                'is_verified' => $this->seller->is_verified,
                'business_name' => $this->seller->relationLoaded('profile') ? $this->seller->profile?->business_name : null,
                'rating' => $this->seller->relationLoaded('profile') ? $this->seller->profile?->rating : null,
                'total_transactions' => $this->seller->relationLoaded('profile') ? $this->seller->profile?->total_transactions : null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
