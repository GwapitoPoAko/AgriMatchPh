<?php

/*
|--------------------------------------------------------------------------
| AgriMatch PH — domain configuration
|--------------------------------------------------------------------------
| Tunables for the Price & Fuel Index Engine, the "Hakot" logistics pooling
| calculator, cooperative pooling and the notification engine. Values are
| deliberately kept in config (not code) so ops can retune them per season
| without a deploy.
*/

return [

    /*
    |----------------------------------------------------------------------
    | Logistics pooling ("Hakot" calculator)
    |----------------------------------------------------------------------
    | Truck tariffs are quoted at the *baseline* fuel price. The dynamic fuel
    | surcharge matrix below then adds (or rebates) a percentage depending on
    | how far the latest weekly DOE pump price has moved from that baseline
    | and on the haul length (fuel is a larger share of long-haul cost).
    */
    'logistics' => [
        // Straight-line km × factor ≈ Mindanao provincial road distance.
        'road_factor' => 1.35,

        // Trucks rarely find a backhaul from the hub, so price ≈ 1.5 legs.
        'backhaul_factor' => 1.5,

        // Farmers within this window of each other's departure can share a truck.
        'pool_date_window_days' => 2,

        // Max detour radius for joining a pool formed in another barangay.
        'max_pickup_radius_km' => 25,

        'vehicles' => [
            'multicab' => ['label' => 'Multicab (1 T)', 'capacity_kg' => 1000, 'fuel' => 'gasoline', 'flag_down' => 900, 'per_km' => 16, 'km_per_liter' => 9.0],
            'elf_4w' => ['label' => 'Isuzu Elf 4-wheeler (3 T)', 'capacity_kg' => 3000, 'fuel' => 'diesel', 'flag_down' => 2200, 'per_km' => 28, 'km_per_liter' => 7.0],
            'fwd_6w' => ['label' => 'Forward 6-wheeler (8 T)', 'capacity_kg' => 8000, 'fuel' => 'diesel', 'flag_down' => 4000, 'per_km' => 42, 'km_per_liter' => 4.5],
            'wingvan_10w' => ['label' => '10-wheeler wing van (14 T)', 'capacity_kg' => 14000, 'fuel' => 'diesel', 'flag_down' => 6500, 'per_km' => 58, 'km_per_liter' => 3.2],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Dynamic Fuel Surcharge Matrix
    |----------------------------------------------------------------------
    | rows    = % change of the latest regional pump price vs baseline
    | columns = haul distance band (short / medium / long)
    | cell    = surcharge % applied to the base tariff (negative = rebate)
    */
    'fuel' => [
        'baseline_price' => [
            'diesel' => (float) env('FUEL_BASELINE_DIESEL', 56.00),
            'gasoline' => (float) env('FUEL_BASELINE_GASOLINE', 60.00),
        ],
        'distance_bands' => [
            'short' => ['label' => '< 60 km', 'max_km' => 60],
            'medium' => ['label' => '60–200 km', 'max_km' => 200],
            'long' => ['label' => '> 200 km', 'max_km' => null],
        ],
        'surcharge_matrix' => [
            ['label' => '≤ −5%', 'min' => null, 'max' => -5, 'short' => -2.0, 'medium' => -3.0, 'long' => -4.0],
            ['label' => '−5% to 0%', 'min' => -5, 'max' => 0, 'short' => 0.0, 'medium' => 0.0, 'long' => 0.0],
            ['label' => '0% to +5%', 'min' => 0, 'max' => 5, 'short' => 1.0, 'medium' => 2.0, 'long' => 3.0],
            ['label' => '+5% to +10%', 'min' => 5, 'max' => 10, 'short' => 3.0, 'medium' => 5.0, 'long' => 6.5],
            ['label' => '+10% to +15%', 'min' => 10, 'max' => 15, 'short' => 5.0, 'medium' => 8.0, 'long' => 10.0],
            ['label' => '+15% to +25%', 'min' => 15, 'max' => 25, 'short' => 8.0, 'medium' => 12.0, 'long' => 15.0],
            ['label' => '> +25%', 'min' => 25, 'max' => null, 'short' => 12.0, 'medium' => 16.0, 'long' => 20.0],
        ],
        // Used when a region has no DOE log yet.
        'fallback_region' => 'X',
    ],

    /*
    |----------------------------------------------------------------------
    | Hub Price Index (PriceIndexService)
    |----------------------------------------------------------------------
    */
    'price_index' => [
        // Trust weights applied on top of traded / reported volume.
        'contract_trust' => 1.0,
        'feed_trust' => 0.6,
        // Contracts are sparse; each verified contract informs a rolling window.
        'contract_window_days' => 7,
        // Weekend / holiday gaps carry the last official feed forward at reduced weight.
        'feed_carry_forward_days' => 2,
        'feed_carry_forward_weight' => 0.5,
        // Farm-gate contracts are normalised to hub-delivered basis with this freight rate.
        'freight_per_kg_km' => 0.012,
        // 7-day change thresholds for listing warnings.
        'signals' => [
            'spike' => 15.0,
            'rising' => 4.0,
            'falling' => -4.0,
            'drop' => -10.0,
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Cooperative pooling
    |----------------------------------------------------------------------
    */
    'coop_pool' => [
        'min_members' => 2,
        'max_members' => 60,
        'default_minimum_order_kg' => 1000,
    ],

    /*
    |----------------------------------------------------------------------
    | Quality grading
    |----------------------------------------------------------------------
    | Listings at or above this volume, or claiming export grade, must request
    | an inspection when they are created (StoreHarvestListingRequest).
    */
    'inspection' => [
        'mandatory_volume_kg' => 50000,
        'grades' => [
            'export' => 'Export Quality',
            'grade_a' => 'Grade A',
            'class_b' => 'Class B',
            'class_c' => 'Class C',
            'rejected' => 'Rejected',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Notifications
    |----------------------------------------------------------------------
    */
    'notifications' => [
        // Types that also go out by SMS to basic phones (when the user opted in).
        'sms_types' => [
            'offer.received', 'offer.countered', 'offer.accepted', 'offer.rejected',
            'contract.awaiting_signature', 'contract.activated', 'coop_pool.allocation', 'coop_pool.joined',
            'inspection.started', 'inspection.completed', 'inspection.declined', 'contract.dispute_resolved',
            'freight.pool_confirmed', 'freight.surcharge_changed', 'freight.trip_assigned',
        ],
        'sms_max_length' => 160,
    ],
];
