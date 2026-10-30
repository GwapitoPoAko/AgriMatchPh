<?php

namespace App\Http\Controllers\Api;

use App\Events\InspectionCompleted;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordInspectionResultRequest;
use App\Http\Requests\RequestInspectionRequest;
use App\Models\HarvestListing;
use App\Models\QualityInspection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Standardized grading workflow:
 *   seller / coop manager requests → inspector records result →
 *   listing gets certified_grade + verification badge (or "rejected").
 */
class QualityInspectionController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {
    }

    /** GET /api/v1/inspections — inspectors see the queue; sellers see their own. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['requested', 'scheduled', 'in_progress', 'passed', 'failed', 'cancelled', 'open'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $user = $request->user();

        $items = QualityInspection::query()
            ->with(['listing:id,reference_code,title,crop_variety_id,municipality,barangay,province_id,seller_id,stock_volume_kg',
                'listing.variety:id,name', 'listing.province:id,name', 'inspector:id,name'])
            ->when(! $user->isAdmin() && ! $user->hasRole(User::ROLE_INSPECTOR), fn ($q) => $q->where(fn ($q) => $q
                ->where('requested_by_id', $user->id)
                ->orWhereHas('listing', fn ($l) => $l->where('seller_id', $user->id))))
            ->when(($validated['status'] ?? null) === 'open', fn ($q) => $q->open())
            ->when(($validated['status'] ?? 'open') !== 'open', fn ($q) => $q->where('status', $validated['status']))
            ->orderByRaw("FIELD(status, 'in_progress', 'scheduled', 'requested', 'failed', 'passed', 'cancelled')")
            ->orderBy('scheduled_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json($items);
    }

    /** POST /api/v1/harvest-listings/{harvestListing}/inspections */
    public function store(RequestInspectionRequest $request, HarvestListing $harvestListing): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        if (! empty($data['image'])) {
            $data['image_url'] = Storage::disk('public')->url(Storage::disk('public')->putFile('harvests', $data['image']));
            unset($data['image']);
        }

        $inspection = DB::transaction(function () use ($data, $user, $harvestListing) {
            $inspection = new QualityInspection([
                'harvest_listing_id' => $harvestListing->id,
                'scheduled_at' => Carbon::parse($data['preferred_date'])->setTime(9, 0),
                'remarks' => trim(($data['claimed_grade'] ?? false ? "Seller claims {$data['claimed_grade']}. " : '') . strip_tags($data['notes'] ?? '')) ?: null,
            ]);
            $inspection->forceFill([
                'reference_code' => QualityInspection::nextReferenceCode(),
                'requested_by_id' => $user->id,
                'cooperative_id' => $harvestListing->cooperative_id,
                'status' => QualityInspection::STATUS_REQUESTED,
                'image_url' => $data['image_url'] ?? null,
            ])->save();

            $harvestListing->forceFill(['inspection_status' => 'pending'])->save();

            $this->audit->record('inspection.requested', $inspection, [
                'reference_code' => $inspection->reference_code, 'listing_id' => $harvestListing->id,
                'preferred_date' => $data['preferred_date'],
            ], actor: $user);

            return $inspection;
        });

        $this->notifications->inspectionRequested($inspection->load('listing.province'));

        return response()->json(['data' => $inspection], 201);
    }

    /** POST /api/v1/inspections/{inspection}/start — inspector claims & starts the visit. */
    public function start(Request $request, QualityInspection $inspection): JsonResponse
    {
        Gate::authorize('record', $inspection);

        $old = ['status' => $inspection->status, 'inspector_id' => $inspection->inspector_id];
        $inspection->forceFill([
            'inspector_id' => $request->user()->id,
            'status' => QualityInspection::STATUS_IN_PROGRESS,
            'scheduled_at' => $inspection->scheduled_at ?? now(),
        ])->save();
        $this->audit->record('inspection.started', $inspection, ['status' => 'in_progress', 'inspector_id' => $request->user()->id], $old);
        $this->notifications->inspectionStarted($inspection->fresh('listing.seller.profile'));

        return response()->json(['data' => $inspection]);
    }

    /** An inspector may decline an unstarted inspection request with a reason. */
    public function decline(Request $request, QualityInspection $inspection): JsonResponse
    {
        Gate::authorize('record', $inspection);
        abort_unless(in_array($inspection->status, [QualityInspection::STATUS_REQUESTED, QualityInspection::STATUS_SCHEDULED], true), 409, 'Only a queued inspection can be declined.');
        $data = $request->validate(['remarks' => ['required', 'string', 'min:5', 'max:1000']]);
        $oldStatus = $inspection->status;
        $inspection->forceFill([
            'status' => QualityInspection::STATUS_CANCELLED,
            'inspector_id' => $request->user()->id,
            'remarks' => trim(strip_tags($data['remarks'])),
        ])->save();
        $inspection->listing()->update(['inspection_status' => 'not_requested']);
        $this->audit->record('inspection.declined', $inspection, ['status' => 'cancelled', 'remarks' => $inspection->remarks], ['status' => $oldStatus], $request->user());
        $this->notifications->inspectionDeclined($inspection->fresh('listing.seller.profile'));

        return response()->json(['data' => $inspection->fresh('listing:id,reference_code,inspection_status')]);
    }

    /** POST /api/v1/inspections/{inspection}/result — inspector records the grade. */
    public function record(RecordInspectionResultRequest $request, QualityInspection $inspection): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        if (! empty($data['image'])) {
            $data['image_url'] = Storage::disk('public')->url(Storage::disk('public')->putFile('harvests', $data['image']));
            unset($data['image']);
        }

        $inspection = DB::transaction(function () use ($data, $user, $inspection) {
            $inspection = QualityInspection::whereKey($inspection->id)->lockForUpdate()->firstOrFail();
            $listing = HarvestListing::whereKey($inspection->harvest_listing_id)->lockForUpdate()->firstOrFail();
            $passed = $data['result'] === 'passed';
            $shelfLife = (int) ($listing->variety()->value('shelf_life_days') ?? 14);

            $inspection->forceFill([
                'inspector_id' => $user->id,
                'status' => $passed ? QualityInspection::STATUS_PASSED : QualityInspection::STATUS_FAILED,
                'certified_grade' => $passed ? $data['certified_grade'] : 'rejected',
                'inspected_at' => now(),
                'sample_size_kg' => $data['sample_size_kg'],
                'moisture_pct' => $data['moisture_pct'] ?? null,
                'defect_pct' => $data['defect_pct'],
                'foreign_matter_pct' => $data['foreign_matter_pct'] ?? null,
                'brix' => $data['brix'] ?? null,
                'pesticide_residue_passed' => $data['pesticide_residue_passed'],
                'remarks' => $data['remarks'] ?? null,
                'image_url' => $data['image_url'] ?? $inspection->image_url,
                'certificate_no' => $passed ? QualityInspection::nextCertificateNo() : null,
                'valid_until' => $passed ? now()->addDays(max(7, intdiv($shelfLife, 2)))->toDateString() : null,
            ])->save();

            $old = ['certified_grade' => $listing->certified_grade, 'inspection_status' => $listing->inspection_status, 'is_verified' => $listing->is_verified];
            $listing->forceFill([
                'certified_grade' => $inspection->certified_grade,
                'inspection_status' => $passed ? 'passed' : 'failed',
                'is_verified' => $passed ? true : $listing->is_verified,
            ])->save();

            $this->audit->record("inspection.{$inspection->status}", $inspection, [
                'certified_grade' => $inspection->certified_grade,
                'certificate_no' => $inspection->certificate_no,
                'defect_pct' => $inspection->defect_pct,
                'listing_id' => $listing->id,
            ], $old, $user);

            InspectionCompleted::dispatch($inspection);

            return $inspection;
        });

        return response()->json(['data' => $inspection->fresh('listing:id,reference_code,certified_grade,inspection_status,is_verified')]);
    }
}
