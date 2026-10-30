<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FuelPolicy;
use App\Services\LogisticsPoolingService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    private const STAFF_ROLES = [User::ROLE_DRIVER, User::ROLE_INSPECTOR, User::ROLE_COOP_MANAGER];

    public function staff(): JsonResponse
    {
        $staff = User::query()->with('profile:id,user_id,business_name,municipality,barangay')
            ->whereIn('role', self::STAFF_ROLES)->orderBy('role')->orderBy('name')->limit(200)->get();

        return response()->json(['data' => $staff->map(fn (User $user) => $this->staffRow($user))]);
    }

    public function createStaff(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(self::STAFF_ROLES)],
        ]);
        $user = User::create([
            'name' => trim(strip_tags($data['name'])),
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
        ]);
        $user->forceFill(['role' => $data['role'], 'status' => 'active'])->save();
        $audit->record('admin.staff_created', $user, ['role' => $user->role], actor: $request->user());

        return response()->json(['data' => $this->staffRow($user->load('profile'))], 201);
    }

    public function updateStaff(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        abort_unless(in_array($user->role, self::STAFF_ROLES, true), 404);
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(self::STAFF_ROLES)],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'is_verified' => ['sometimes', 'boolean'],
        ]);
        $old = ['role' => $user->role, 'status' => $user->status, 'is_verified' => $user->is_verified];
        $user->forceFill($data)->save();
        $audit->record('admin.staff_updated', $user, ['role' => $user->role, 'status' => $user->status, 'is_verified' => $user->is_verified], $old, $request->user());

        return response()->json(['data' => $this->staffRow($user->fresh('profile'))]);
    }

    public function disputes(): JsonResponse
    {
        $items = Contract::query()->with(['buyer:id,name', 'seller:id,name', 'listing:id,title,reference_code'])
            ->where('status', Contract::STATUS_DISPUTED)->orderByDesc('updated_at')->limit(100)->get()
            ->map(fn (Contract $contract) => [
                'id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'listing' => $contract->listing?->only(['title', 'reference_code']),
                'buyer' => $contract->buyer?->only(['id', 'name']),
                'seller' => $contract->seller?->only(['id', 'name']),
                'total_value' => $contract->total_value,
                'notes' => $contract->notes,
                'updated_at' => $contract->updated_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $items]);
    }

    public function resolveDispute(Request $request, Contract $contract, AuditLogger $audit, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', Rule::in([Contract::STATUS_COMPLETED, Contract::STATUS_CANCELLED])],
            'notes' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        abort_unless($contract->status === Contract::STATUS_DISPUTED, 409, 'Only disputed contracts can be resolved.');
        $old = ['status' => $contract->status, 'notes' => $contract->notes];
        $contract->forceFill([
            'status' => $data['resolution'],
            'completed_at' => $data['resolution'] === Contract::STATUS_COMPLETED ? now() : $contract->completed_at,
            'notes' => trim(($contract->notes ? $contract->notes . "\n\n" : '') . 'Admin resolution: ' . strip_tags($data['notes'])),
        ])->save();
        $audit->record('contract.dispute_resolved', $contract, ['status' => $contract->status, 'resolution' => strip_tags($data['notes'])], $old, $request->user());

        foreach ([$contract->buyer, $contract->seller] as $party) {
            if ($party) {
                $notifications->notify(
                    $party,
                    'contract.dispute_resolved',
                    "Dispute resolved: {$contract->contract_number}",
                    'Platform staff resolved the contract dispute. Sign in to review the resolution details.',
                    ['contract_id' => $contract->id, 'resolution' => $contract->status],
                    '/contracts',
                    'high',
                    true,
                );
            }
        }

        return response()->json(['data' => ['id' => $contract->id, 'status' => $contract->status]]);
    }

    public function fuelPolicy(): JsonResponse
    {
        return response()->json([
            'data' => [
                'baseline_diesel' => FuelPolicy::baseline('diesel'),
                'surcharge_multiplier' => FuelPolicy::surchargeMultiplier(),
                'persisted' => Schema::hasTable('platform_settings'),
                'latest_doe_logged_diesel' => DB::table('fuel_price_logs')->where('fuel_type', 'diesel')->orderByDesc('effective_date')->value('price_per_liter'),
            ],
        ]);
    }

    public function updateFuelPolicy(Request $request, AuditLogger $audit, LogisticsPoolingService $logistics): JsonResponse
    {
        abort_unless(Schema::hasTable('platform_settings'), 503, 'Run the additive platform settings migration before saving fuel policy controls.');
        $data = $request->validate([
            'baseline_diesel' => ['required', 'numeric', 'min:20', 'max:200'],
            'surcharge_multiplier' => ['required', 'numeric', 'min:0', 'max:3'],
        ]);
        $old = ['baseline_diesel' => FuelPolicy::baseline('diesel'), 'surcharge_multiplier' => FuelPolicy::surchargeMultiplier()];
        FuelPolicy::store('fuel_baseline_diesel', (float) $data['baseline_diesel']);
        FuelPolicy::store('fuel_surcharge_multiplier', (float) $data['surcharge_multiplier']);
        $updated = ['baseline_diesel' => FuelPolicy::baseline('diesel'), 'surcharge_multiplier' => FuelPolicy::surchargeMultiplier()];
        $audit->record('admin.fuel_policy_updated', null, $updated, $old, $request->user());
        $repriced = $logistics->recalculateOpenPools();

        return response()->json(['data' => $updated, 'repriced_pools' => $repriced['repriced']]);
    }

    private function staffRow(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'is_verified' => $user->is_verified,
            'profile' => $user->profile ? [
                'business_name' => $user->profile->business_name,
                'municipality' => $user->profile->municipality,
                'barangay' => $user->profile->barangay,
            ] : null,
        ];
    }
}
