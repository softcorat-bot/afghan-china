<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Expense;
use App\Support\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop running costs. Money that leaves the business without becoming stock —
 * rent, power, transport, repairs — so the owner can see it next to revenue
 * instead of only seeing what was spent on inventory.
 */
class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Expense::with('user:id,name')
            ->when($request->filled('from'), fn ($q) => $q->whereDate('spent_on', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('spent_on', '<=', $request->query('to')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('payee', 'like', $s)
                    ->orWhere('reference', 'like', $s)
                    ->orWhere('note', 'like', $s));
            })
            ->orderByDesc('spent_on')->orderByDesc('id')
            ->limit(500)->get();

        return response()->json([
            'data' => $rows,
            'categories' => Expense::CATEGORIES,
            'total' => round((float) $rows->sum('amount'), 2),
            'base' => 'AFN',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $expense = Expense::create($this->validated($request) + ['user_id' => $request->user()->id, 'branch_id' => Branch::id()]);

        ActivityLog::log('created', 'Expense', "Recorded {$expense->category} expense of {$expense->amount} AFN");

        return response()->json($expense->load('user:id,name'), 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $expense->update($this->validated($request));

        ActivityLog::log('updated', 'Expense', "Updated expense #{$expense->id}");

        return response()->json($expense->load('user:id,name'));
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $id = $expense->id;
        $expense->delete();

        ActivityLog::log('deleted', 'Expense', "Deleted expense #{$id}");

        return response()->json(['message' => 'Deleted.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'spent_on' => ['required', 'date'],
            'category' => ['required', 'string', 'in:'.implode(',', Expense::CATEGORIES)],
            'payee' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'method' => ['required', 'in:cash,bank,mobile'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);
    }
}
