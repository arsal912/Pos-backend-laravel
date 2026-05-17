<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Mail\VerifyEmail;
use App\Models\Branch;
use App\Models\EmailVerificationToken;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    use ApiResponse;

    /**
     * Register a new store with its owner.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Owner info
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',

            // Store info
            'store_name' => 'required|string|max:255',
            'business_type' => 'nullable|string|max:100',
            'store_email' => 'required|email|unique:stores,email',
            'store_phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',

            // Plan
            'plan_id' => 'nullable|exists:plans,id',
        ]);

        try {
            $result = DB::transaction(function () use ($validated) {
                // 1. Determine plan (default: free trial / cheapest)
                $plan = isset($validated['plan_id'])
                    ? Plan::findOrFail($validated['plan_id'])
                    : Plan::where('is_active', true)->orderBy('price')->first();

                // 2. Create store
                $store = Store::create([
                    'name' => $validated['store_name'],
                    'slug' => $this->generateUniqueSlug($validated['store_name']),
                    'business_type' => $validated['business_type'] ?? 'general',
                    'email' => $validated['store_email'],
                    'phone' => $validated['store_phone'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'country' => $validated['country'] ?? 'PK',
                    'currency' => $validated['currency'] ?? 'PKR',
                    'timezone' => 'Asia/Karachi',
                    'status' => 'active',
                    'is_active' => true,
                    'trial_ends_at' => now()->addDays(
                        $plan?->trial_days ?? config('app.free_trial_days', 14)
                    ),
                ]);

                // 3. Create main branch
                $branch = Branch::create([
                    'store_id' => $store->id,
                    'name' => 'Main Branch',
                    'code' => 'MAIN',
                    'is_main' => true,
                    'is_active' => true,
                ]);

                // 4. Create owner user
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                    'phone' => $validated['phone'] ?? null,
                    'store_id' => $store->id,
                    'branch_id' => $branch->id,
                    'is_super_admin' => false,
                    'is_active' => true,
                    'email_verified_at' => null,
                ]);

                $user->assignRole('store-owner');

                $verificationToken = EmailVerificationToken::generateFor($user);
                Mail::to($user->email)->send(new VerifyEmail($user, $verificationToken->token));

                // 5. Create subscription (pending payment if paid plan)
                if ($plan) {
                    Subscription::create([
                        'store_id' => $store->id,
                        'plan_id' => $plan->id,
                        'status' => $plan->price > 0 ? 'pending' : 'active',
                        'starts_at' => now(),
                        'ends_at' => $plan->billing_cycle === 'yearly'
                            ? now()->addYear()
                            : now()->addMonth(),
                        'amount' => $plan->price,
                        'currency' => $plan->currency,
                        'billing_cycle' => $plan->billing_cycle,
                    ]);
                }

                return compact('user', 'store', 'plan', 'branch');
            });

            // Create auth token
            $token = $result['user']->createToken('registration')->plainTextToken;

            return $this->successResponse([
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $result['user']->id,
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'phone' => $result['user']->phone,
                    'avatar' => $result['user']->avatar,
                    'is_super_admin' => $result['user']->isSuperAdmin(),
                    'is_active' => $result['user']->is_active,
                    'store_id' => $result['user']->store_id,
                    'branch_id' => $result['user']->branch_id,
                    'roles' => $result['user']->getRoleNames(),
                    'permissions' => $result['user']->getAllPermissions()->pluck('name'),
                    'email_verified_at' => $result['user']->email_verified_at,
                    'store' => [
                        'id' => $result['store']->id,
                        'name' => $result['store']->name,
                        'slug' => $result['store']->slug,
                        'logo' => $result['store']->logo,
                        'currency' => $result['store']->currency,
                        'status' => $result['store']->status,
                        'trial_ends_at' => $result['store']->trial_ends_at,
                    ],
                    'branch' => [
                        'id' => $result['user']->branch_id,
                        'name' => $result['branch']->name,
                    ],
                ],
                'requires_payment' => ($result['plan']?->price ?? 0) > 0,
            ], 'Registration successful', 201);
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'Registration failed: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Check if email is available.
     */
    public function checkEmail(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $available = !User::where('email', $request->email)->exists();

        return $this->successResponse(['available' => $available]);
    }

    /**
     * Check if store name/slug is available.
     */
    public function checkStoreName(Request $request): JsonResponse
    {
        $request->validate(['name' => 'required|string']);

        $slug = Str::slug($request->name);
        $available = !Store::where('slug', $slug)->exists();

        return $this->successResponse([
            'available' => $available,
            'suggested_slug' => $slug,
        ]);
    }

    protected function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $original = $slug;
        $counter = 1;

        while (Store::where('slug', $slug)->exists()) {
            $slug = $original . '-' . $counter++;
        }

        return $slug;
    }
}
