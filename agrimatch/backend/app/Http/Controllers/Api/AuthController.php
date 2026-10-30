<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Two authentication modes, both via Laravel Sanctum:
 *
 *  1. SPA (default for the React PWA): GET /sanctum/csrf-cookie, then
 *     POST /api/v1/auth/login with X-XSRF-TOKEN. Creates an HttpOnly session
 *     cookie; CSRF is enforced on every state-changing request.
 *  2. Token (basic/mobile clients): POST /api/v1/auth/token → Bearer token
 *     with role-scoped abilities and a 30-day expiry.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = $this->verifiedUser($credentials['email'], $credentials['password']);

        Auth::guard('web')->login($user, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate(); // session fixation defense

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login', $user, ['mode' => 'spa'], actor: $user);

        return response()->json(['user' => $this->present($user)]);
    }

    /** Public self-registration is limited to farmer and buyer accounts. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in([User::ROLE_FARMER, User::ROLE_BUYER])],
            'business_name' => ['required', 'string', 'max:255'],
            'province_id' => ['nullable', 'integer', 'exists:provinces,id'],
            'municipality' => ['nullable', 'string', 'max:120'],
            'barangay' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:4.5,21.5', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:116,127', 'required_with:latitude'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => trim(strip_tags($data['name'])),
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);
            $user->forceFill(['role' => $data['role'], 'status' => 'active', 'is_verified' => false])->save();
            $user->profile()->create([
                'business_name' => trim(strip_tags($data['business_name'])),
                'business_type' => $data['role'] === User::ROLE_FARMER ? 'individual_farm' : 'supermarket',
                'province_id' => $data['province_id'] ?? null,
                'municipality' => isset($data['municipality']) ? trim(strip_tags($data['municipality'])) : null,
                'barangay' => isset($data['barangay']) ? trim(strip_tags($data['barangay'])) : null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'sms_opt_in' => true,
            ]);
            $this->audit->record('auth.registered', $user, ['role' => $data['role']], actor: null);

            return $user;
        });

        return response()->json(['user' => $this->present($user)], 201);
    }

    public function token(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $user = $this->verifiedUser($credentials['email'], $credentials['password']);

        $abilities = match ($user->role) {
            User::ROLE_ADMIN => ['*'],
            User::ROLE_FARMER => ['listings:write', 'offers:respond', 'freight:write'],
            User::ROLE_COOP_MANAGER => ['listings:write', 'offers:respond', 'pools:write', 'freight:write'],
            User::ROLE_INSPECTOR => ['inspections:write'],
            User::ROLE_BUYER => ['offers:write', 'demand:write'],
            User::ROLE_TRADER => ['listings:write', 'offers:write', 'offers:respond', 'demand:write', 'freight:write'],
            User::ROLE_DRIVER => ['freight:status'],
            default => [],
        };

        $token = $user->createToken($credentials['device_name'] ?? 'agrimatch-mobile', $abilities, now()->addDays(30));
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.token_issued', $user, ['device' => $credentials['device_name'] ?? 'agrimatch-mobile'], actor: $user);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => $this->present($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    /* ------------------------------------------------------------------ */

    private function verifiedUser(string $email, string $password): User
    {
        /** @var User|null $user */
        $user = User::where('email', $email)->first();

        // Constant-time-ish: always run a hash check so response timing doesn't reveal valid emails.
        $valid = $user ? Hash::check($password, $user->password) : Hash::check($password, '$2y$12$' . str_repeat('a', 53));

        if (! $user || ! $valid) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is not active. Contact support.']);
        }

        return $user;
    }

    private function present(User $user): array
    {
        $user->loadMissing('profile.province:id,name', 'profile.cooperative:id,name');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_verified' => $user->is_verified,
            'profile' => $user->profile ? [
                'business_name' => $user->profile->business_name,
                'business_type' => $user->profile->business_type,
                'province' => $user->profile->province?->name,
                'province_id' => $user->profile->province_id,
                'municipality' => $user->profile->municipality,
                'barangay' => $user->profile->barangay,
                'latitude' => $user->profile->latitude,
                'longitude' => $user->profile->longitude,
                'cooperative' => $user->profile->cooperative?->only(['id', 'name']),
                'sms_opt_in' => $user->profile->sms_opt_in,
            ] : null,
            'managed_cooperative_id' => $user->role === User::ROLE_COOP_MANAGER ? $user->managedCooperatives()->value('id') : null,
        ];
    }
}
