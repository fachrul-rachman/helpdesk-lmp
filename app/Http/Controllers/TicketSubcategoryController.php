<?php

namespace App\Http\Controllers;

use App\Models\TicketSubcategory;
use Illuminate\Http\Request;

class TicketSubcategoryController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'division_id' => ['nullable', 'uuid', 'exists:divisions,id'],
        ]);

        $items = TicketSubcategory::query()
            ->where('is_active', true)
            ->where(function ($query) use ($validated): void {
                $query->whereNull('division_id');
                if (! empty($validated['division_id'])) {
                    $query->orWhere('division_id', $validated['division_id']);
                }
            })
            ->orderBy('name')
            ->get(['id', 'division_id', 'name']);

        return response()->json([
            'data' => [
                'global' => $items->whereNull('division_id')->values(),
                'division' => $items->whereNotNull('division_id')->values(),
            ],
        ]);
    }
}
