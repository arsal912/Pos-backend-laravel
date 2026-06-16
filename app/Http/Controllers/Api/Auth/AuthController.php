<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * Login user and return token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:255',
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Check account lockout before verifying credentials
        if ($user && $user->locked_until && Carbon::now()->lt($user->locked_until)) {
            $lockedUntil = Carbon::parse($user->locked_until)->format('Y-m-d H:i:s');
            return response()->json([
                'message' => "Account locked until {$lockedUntil}. Try again later.",
            ], 423);
        }

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            // Increment failed attempts and potentially lock the account
            if ($user) {
                // Direct assignment — login_attempts and locked_until are server-controlled,
                // not in $fillable.
                $user->login_attempts = $user->login_attempts + 1;
                if ($user->login_attempts >= 5) {
                    $user->locked_until = Carbon::now()->addMinutes(15);
                }
                $user->save();
            }

            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if (!$user->is_active) {
            return $this->forbiddenResponse('Your account has been deactivated.');
        }

        // If user belongs to a store, ensure store is active
        if ($user->store_id && !$user->isSuperAdmin()) {
            $store = $user->store;
            if (!$store || !$store->is_active) {
                return $this->forbiddenResponse('Your store is not active.');
            }
        }

        // Successful login: reset lockout state and update last login.
        // Direct assignment — these are server-controlled fields, not in $fillable.
        $user->login_attempts = 0;
        $user->locked_until = null;
        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->save();

        $deviceName = $validated['device_name'] ?? $request->userAgent() ?? 'Unknown';
        $token = $user->createToken($deviceName)->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->formatUser($user),
        ], 'Login successful');
    }

    /**
     * Clear account lockout for a given user (admin use only).
     */
    public function clearLockout(Request $request, int $userId): JsonResponse
    {
        $target = User::findOrFail($userId);

        // Direct assignment — server-controlled fields, not in $fillable.
        $target->login_attempts = 0;
        $target->locked_until = null;
        $target->save();

        return $this->successResponse(null, "Lockout cleared for user {$target->email}.");
    }

    /**
     * Get authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['store', 'branch', 'warehouse', 'roles']);

        return $this->successResponse(
            $this->formatUser($user),
            'User retrieved successfully'
        );
    }

    /**
     * Logout user (revoke current token).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(null, 'Logged out successfully');
    }

    /**
     * Logout from all devices (revoke all tokens).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return $this->successResponse(null, 'Logged out from all devices');
    }

    protected function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'is_super_admin' => $user->isSuperAdmin(),
            'is_active' => $user->is_active,
            'store_id'     => $user->store_id,
            'branch_id'    => $user->branch_id,
            'warehouse_id' => $user->warehouse_id,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'store' => $user->store ? [
                'id' => $user->store->id,
                'name' => $user->store->name,
                'slug' => $user->store->slug,
                'logo' => $user->store->logo,
                'currency' => $user->store->currency,
                'status' => $user->store->status,
                'trial_ends_at' => $user->store->trial_ends_at,
            ] : null,
            'branch' => $user->branch ? [
                'id'   => $user->branch->id,
                'name' => $user->branch->name,
                'code' => $user->branch->code,
            ] : null,
            'warehouse' => $user->warehouse ? [
                'id'   => $user->warehouse->id,
                'name' => $user->warehouse->name,
                'code' => $user->warehouse->code,
                'type' => $user->warehouse->type,
            ] : null,
            'email_verified_at' => optional($user->email_verified_at)->toDateTimeString(),
        ];
    }
}
