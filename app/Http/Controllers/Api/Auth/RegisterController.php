<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Mail\VerifyEmail;
use App\Models\Branch;
use App\Jobs\SyncStoreAggregate;
use App\Models\EmailVerificationToken;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\DatabaseManager as TenancyDatabaseManager;

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

        $result = null;

        try {
            $result = DB::transaction(function () use ($validated) {
                // 1. Determine plan (default: free trial / cheapest)
                $plan = isset($validated['plan_id'])
                    ? Plan::findOrFail($validated['plan_id'])
                    : Plan::where('is_active', true)->orderBy('price')->first();

                // 2. Create store.
                // status and is_active are excluded from $fillable — set them via
                // direct property assignment to avoid mass-assignment exposure.
                $store = new Store([
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
                    'trial_ends_at' => now()->addDays(
                        $plan?->trial_days ?? config('app.free_trial_days', 14)
                    ),
                ]);
                $store->status    = 'active';
                $store->is_active = true;
                $store->save();

                // 3. Create owner user without branch assignment yet.
                // store_id, branch_id, is_super_admin, and email_verified_at are
                // excluded from $fillable (server-controlled); set them directly.
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                    'phone' => $validated['phone'] ?? null,
                    'is_active' => true,
                ]);
                $user->store_id = $store->id;
                $user->branch_id = null;
                $user->is_super_admin = false;
                $user->email_verified_at = null;
                $user->save();

                $user->assignRole('store-owner');

                return compact('user', 'store', 'plan');
            });

            $verificationToken = EmailVerificationToken::generateFor($result['user']);
            Mail::to($result['user']->email)->send(new VerifyEmail($result['user'], $verificationToken->token));
            $verificationUrl = $verificationToken->getVerificationUrl();

            $this->createTenantDatabaseAndRunMigrations($result['store']);

            $branch = $result['store']->run(function ($tenant) {
                return Branch::create([
                    'store_id' => $tenant->id,
                    'name' => 'Main Branch',
                    'code' => 'MAIN',
                    'is_main' => true,
                    'is_active' => true,
                ]);
            });

            $result['user']->branch_id = $branch->id;
            $result['user']->save();

            if ($result['plan']) {
                $result['store']->run(function ($tenant) use ($result) {
                    // store_id is excluded from $fillable — set via direct assignment.
                    $subscription = new Subscription([
                        'plan_id' => $result['plan']->id,
                        'status' => $result['plan']->price > 0 ? 'pending' : 'active',
                        'starts_at' => now(),
                        'ends_at' => $result['plan']->billing_cycle === 'yearly'
                            ? now()->addYear()
                            : now()->addMonth(),
                        'amount' => $result['plan']->price,
                        'currency' => $result['plan']->currency,
                        'billing_cycle' => $result['plan']->billing_cycle,
                    ]);
                    $subscription->store_id = $tenant->id;
                    $subscription->save();
                });
            }

            SyncStoreAggregate::dispatch($result['store']);

            // Create auth token
            $token = $result['user']->createToken('registration')->plainTextToken;

            $payload = [
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
                        'name' => $branch->name,
                    ],
                ],
                'requires_payment' => ($result['plan']?->price ?? 0) > 0,
            ];

            if (config('mail.default') === 'log' || app()->environment('local')) {
                $payload['verification_url'] = $verificationUrl;
            }

            return $this->successResponse($payload, 'Registration successful', 201);
        } catch (\Throwable $e) {
            if ($result !== null) {
                $this->cleanupFailedTenantSignup($result['store'], $result['user']);
            }

            return $this->errorResponse(
                'Registration failed: ' . $e->getMessage(),
                500
            );
        }
    }

    private function createTenantDatabaseAndRunMigrations(Store $store): void
    {
        $store->database()->makeCredentials();
        app(TenancyDatabaseManager::class)->ensureTenantCanBeCreated($store);
        $store->database()->manager()->createDatabase($store);

        Artisan::call('tenants:migrate', [
            '--tenants' => [$store->getTenantKey()],
            '--force' => true,
        ]);
    }

    private function cleanupFailedTenantSignup(Store $store, User $user): void
    {
        try {
            $tenantDatabaseName = $store->database()->getName();
            $store->database()->manager()->deleteDatabase($store);
        } catch (\Throwable $e) {
            // Ignore cleanup failure.
        }

        try {
            $user->delete();
            $store->delete();
        } catch (\Throwable $e) {
            // Ignore cleanup failure.
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
