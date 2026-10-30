<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\B2bOfferController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\CoopPoolController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FreightController;
use App\Http\Controllers\Api\FuelPriceController;
use App\Http\Controllers\Api\HarvestListingController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\MatchingController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PriceIndexController;
use App\Http\Controllers\Api\QualityInspectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AgriMatch PH — REST API v1   (prefix: /api/v1)
|--------------------------------------------------------------------------
| Global: throttle:api (60 req/min per user/IP — see AppServiceProvider),
| Sanctum stateful SPA auth with CSRF on state-changing requests, request ids.
| Mutations accept X-Idempotency-Key so the offline outbox can replay safely.
*/

Route::prefix('v1')->middleware('throttle:60,1')->group(function () {

    // ---- Auth ------------------------------------------------------------
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:login');
    Route::post('auth/token', [AuthController::class, 'token'])->middleware('throttle:login');

    // ---- Public read endpoints -------------------------------------------
    Route::get('lookups/provinces', [LookupController::class, 'provinces']);
    Route::get('lookups/crops', [LookupController::class, 'crops']);
    Route::get('lookups/trading-hubs', [LookupController::class, 'tradingHubs']);
    Route::get('lookups/cooperatives', [LookupController::class, 'cooperatives']);
    Route::get('lookups/vehicles', [LookupController::class, 'vehicles']);

    Route::get('dashboard/summary', [DashboardController::class, 'summary']);

    Route::get('harvest-listings', [HarvestListingController::class, 'index']);
    Route::get('harvest-listings/{harvestListing}', [HarvestListingController::class, 'show'])->whereNumber('harvestListing');
    Route::get('harvest-listings/{harvestListing}/freight-estimate', [HarvestListingController::class, 'freightEstimate'])->whereNumber('harvestListing');

    Route::get('price-index/trends', [PriceIndexController::class, 'trends']);
    Route::get('price-index/ticker', [PriceIndexController::class, 'ticker']);
    Route::get('price-index/regional', [PriceIndexController::class, 'regional']);
    Route::get('price-index/hubs', [PriceIndexController::class, 'hubs']);
    Route::get('price-index/signals', [PriceIndexController::class, 'signals']);

    Route::get('coop-pools', [CoopPoolController::class, 'index']);
    Route::get('fuel-prices', [FuelPriceController::class, 'index']);
    Route::post('freight/quote', [FreightController::class, 'quote']);

    // Anonymous supply discovery is allowed; signed-in buyers' own listings are excluded.
    Route::post('matching/search', [MatchingController::class, 'search']);

    // ---- Authenticated -----------------------------------------------------
    Route::middleware(['auth:sanctum', 'idempotent'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Listings lifecycle (HarvestListingPolicy)
        Route::post('harvest-listings', [HarvestListingController::class, 'store']);
        Route::match(['put', 'patch'], 'harvest-listings/{harvestListing}', [HarvestListingController::class, 'update']);
        Route::delete('harvest-listings/{harvestListing}', [HarvestListingController::class, 'destroy']);
        Route::get('harvest-listings/{harvestListing}/matches', [HarvestListingController::class, 'matches']);

        // Quality grading
        Route::get('inspections', [QualityInspectionController::class, 'index']);
        Route::post('harvest-listings/{harvestListing}/inspections', [QualityInspectionController::class, 'store']);
        Route::post('inspections/{inspection}/start', [QualityInspectionController::class, 'start'])->middleware('role:quality_inspector,admin');
        Route::post('inspections/{inspection}/decline', [QualityInspectionController::class, 'decline'])->middleware('role:quality_inspector,admin');
        Route::post('inspections/{inspection}/result', [QualityInspectionController::class, 'record'])->middleware('role:quality_inspector,admin');

        // Cooperative pooling
        Route::get('cooperatives/{cooperative}/pool-candidates', [CoopPoolController::class, 'candidates']);
        Route::post('coop-pools', [CoopPoolController::class, 'store'])->middleware('role:coop_manager,admin');
        Route::post('coop-pools/{harvestListing}/dissolve', [CoopPoolController::class, 'dissolve'])->middleware('role:coop_manager,admin');

        // Matching
        Route::get('buyer-demands/{buyerDemand}/matches', [MatchingController::class, 'forDemand']);

        // Negotiation & contracts
        Route::get('offers', [B2bOfferController::class, 'index']);
        Route::post('offers', [B2bOfferController::class, 'store'])->middleware('throttle:offers');
        Route::post('offers/{offer}/respond', [B2bOfferController::class, 'respond'])->middleware('throttle:offers');
        Route::get('contracts', [ContractController::class, 'index']);
        Route::get('contracts/{contract}', [ContractController::class, 'show']);
        Route::post('contracts/{contract}/sign', [ContractController::class, 'sign']);

        // Shared freight ("Hakot")
        Route::get('freight/pools', [FreightController::class, 'index']);
        Route::post('freight/pools', [FreightController::class, 'store']);
        Route::get('freight/pools/{freightPool}', [FreightController::class, 'show']);
        Route::post('freight/pools/{freightPool}/join', [FreightController::class, 'join']);
        Route::patch('freight/pools/{freightPool}/status', [FreightController::class, 'updateStatus']);

        // Notifications (web bell)
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereNumber('notification');

        // Admin: price & fuel index engine, audit trail
        Route::post('fuel-prices', [FuelPriceController::class, 'store'])->middleware('role:admin');
        Route::post('price-index/recompute', [PriceIndexController::class, 'recompute'])->middleware('role:admin');
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('role:admin');
        Route::get('audit-logs/verify', [AuditLogController::class, 'verify'])->middleware('role:admin');

        // Super-admin staff and governance controls.
        Route::get('admin/staff', [\App\Http\Controllers\Api\AdminController::class, 'staff'])->middleware('role:admin');
        Route::post('admin/staff', [\App\Http\Controllers\Api\AdminController::class, 'createStaff'])->middleware('role:admin');
        Route::patch('admin/staff/{user}', [\App\Http\Controllers\Api\AdminController::class, 'updateStaff'])->middleware('role:admin');
        Route::get('admin/disputes', [\App\Http\Controllers\Api\AdminController::class, 'disputes'])->middleware('role:admin');
        Route::patch('admin/disputes/{contract}', [\App\Http\Controllers\Api\AdminController::class, 'resolveDispute'])->middleware('role:admin');
        Route::get('admin/fuel-policy', [\App\Http\Controllers\Api\AdminController::class, 'fuelPolicy'])->middleware('role:admin');
        Route::patch('admin/fuel-policy', [\App\Http\Controllers\Api\AdminController::class, 'updateFuelPolicy'])->middleware('role:admin');
    });
});
