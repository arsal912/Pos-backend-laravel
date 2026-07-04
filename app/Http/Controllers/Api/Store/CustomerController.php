<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = Customer::with('group');

        if ($search = $request->input('search')) {
            $query->search($search);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $customers = $query->orderBy('name')->paginate($request->input('per_page', 20));

        return $this->paginatedResponse($customers);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('view-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        return $this->successResponse(['customer' => Customer::findOrFail($id)]);
    }

    /**
     * Fast phone/name lookup for POS screen.
     * GET /customers/lookup?phone=03001234567
     */
    public function lookup(Request $request): JsonResponse
    {
        $term = $request->input('phone') ?? $request->input('q') ?? $request->input('search');

        if (! $term) {
            return $this->errorResponse('phone or q parameter required.', 422);
        }

        $customer = Customer::where('phone', $term)
            ->orWhere('email', $term)
            ->orWhere('code', $term)
            ->first();

        if (! $customer) {
            // Return near-matches via FULLTEXT search
            $suggestions = Customer::search($term)->active()->limit(5)->get();
            return $this->successResponse(['customer' => null, 'suggestions' => $suggestions]);
        }

        return $this->successResponse(['customer' => $customer]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $this->validateCustomer($request);
        $validated['code'] = Customer::generateCode();

        $customer = Customer::create($validated);

        return $this->successResponse(['customer' => $customer], 'Customer created.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $customer  = Customer::findOrFail($id);
        $validated = $this->validateCustomer($request, $id);
        $customer->update($validated);

        return $this->successResponse(['customer' => $customer->fresh()], 'Customer updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $customer = Customer::findOrFail($id);

        if ($request->boolean('hard')) {
            // Hard delete: anonymize personal data but preserve transaction records
            // (cannot fully delete — sales records needed for tax/accounting)
            $customer->update([
                'name'              => 'DELETED-' . $customer->id,
                'email'             => null,
                'phone'             => null,
                'company'           => null,
                'tax_number'        => null,
                'billing_address'   => null,
                'shipping_address'  => null,
                'date_of_birth'     => null,
                'notes'             => null,
                'referral_code'     => null,
                'tags'              => null,
            ]);
            // Add to opt-outs for all channels
            foreach (['sms', 'email', 'whatsapp'] as $channel) {
                if ($customer->phone || $customer->email) {
                    \App\Models\CommunicationOptOut::firstOrCreate([
                        'channel'   => $channel,
                        'recipient' => $channel === 'email' ? ('deleted-' . $customer->id . '@deleted.invalid') : ('deleted-' . $customer->id),
                    ], ['reason' => 'gdpr_erasure', 'opted_out_at' => now()]);
                }
            }
            $customer->delete(); // soft delete for audit trail
            return $this->successResponse(null, 'Customer data permanently anonymized. Transaction records preserved for compliance.');
        }

        $customer->delete(); // regular soft delete
        return $this->successResponse(null, 'Customer deleted.');
    }

    /**
     * Purchase history — placeholder until sales tables exist (Step 6).
     * GET /customers/{id}/purchases
     */
    public function purchases(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('view-customers')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $customer = Customer::findOrFail($id);

        // Sales model will exist after Step 6 — return empty for now
        $sales = [];

        if (class_exists(\App\Models\Sale::class)) {
            $sales = \App\Models\Sale::where('customer_id', $id)
                ->with(['items.product:id,name,sku'])
                ->latest('sale_date')
                ->paginate($request->input('per_page', 15));
            return $this->paginatedResponse($sales);
        }

        return $this->successResponse([
            'customer' => $customer,
            'sales'    => [],
            'total_spent' => 0,
        ]);
    }

    private function validateCustomer(Request $request, ?int $id = null): array
    {
        $emailUnique = $id
            ? "nullable|email|unique:customers,email,{$id}"
            : 'nullable|email|unique:customers,email';

        return $request->validate([
            'name'             => ($id ? 'sometimes' : 'required') . '|string|max:255',
            'email'            => $emailUnique,
            'phone'            => 'nullable|string|max:50',
            'company'          => 'nullable|string|max:255',
            'tax_number'       => 'nullable|string|max:100',
            'billing_address'  => 'nullable|string',
            'shipping_address' => 'nullable|string',
            'city'             => 'nullable|string|max:100',
            'country'          => 'nullable|string|max:100',
            'date_of_birth'    => 'nullable|date',
            'gender'           => 'nullable|in:male,female,other,prefer_not_say',
            'opening_balance'  => 'sometimes|numeric',
            'credit_limit'     => 'nullable|numeric|min:0',
            'notes'            => 'nullable|string',
            'is_active'        => 'sometimes|boolean',
        ]);
    }
}
