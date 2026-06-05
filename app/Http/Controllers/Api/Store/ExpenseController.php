<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-expenses')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = Expense::query();

        if ($request->filled('date_from')) $query->where('expense_date', '>=', $request->input('date_from'));
        if ($request->filled('date_to'))   $query->where('expense_date', '<=', $request->input('date_to'));
        if ($request->filled('category'))  $query->where('category', 'like', '%' . $request->input('category') . '%');
        if ($request->filled('branch_id')) $query->where('branch_id', $request->input('branch_id'));

        return $this->paginatedResponse($query->latest('expense_date')->paginate($request->input('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-expenses')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'expense_date'   => 'required|date',
            'category'       => 'required|string|max:100',
            'description'    => 'required|string|max:255',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'sometimes|in:cash,card,bank_transfer,cheque,other',
            'branch_id'      => 'nullable|integer',
            'reference'      => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
        ]);

        $expense = Expense::create($validated);

        return $this->successResponse(['expense' => $expense], 'Expense recorded.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-expenses')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $expense   = Expense::findOrFail($id);
        $validated = $request->validate([
            'expense_date'   => 'sometimes|date',
            'category'       => 'sometimes|string|max:100',
            'description'    => 'sometimes|string|max:255',
            'amount'         => 'sometimes|numeric|min:0.01',
            'payment_method' => 'sometimes|in:cash,card,bank_transfer,cheque,other',
            'branch_id'      => 'nullable|integer',
            'reference'      => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
        ]);

        $expense->update($validated);

        return $this->successResponse(['expense' => $expense->fresh()], 'Expense updated.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('manage-expenses')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        Expense::findOrFail($id)->delete();

        return $this->successResponse(null, 'Expense deleted.');
    }

    /** List distinct expense categories used by this store. */
    public function categories(): JsonResponse
    {
        $cats = Expense::whereNull('deleted_at')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return $this->successResponse(['categories' => $cats]);
    }
}
