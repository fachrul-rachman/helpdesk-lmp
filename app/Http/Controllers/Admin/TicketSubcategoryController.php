<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketSubcategoryController extends Controller
{
    public function index()
    {
        $items = TicketSubcategory::query()
            ->with('division:id,name')
            ->orderByRaw('division_id is not null')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $subcategory = TicketSubcategory::create($data);

        AuditLogger::log('admin.ticket_subcategory.created', $subcategory, ['data' => $subcategory->toArray()]);

        return response()->json(['data' => $subcategory->load('division:id,name')], 201);
    }

    public function update(Request $request, string $id)
    {
        $subcategory = TicketSubcategory::query()->findOrFail($id);
        $before = $subcategory->toArray();
        $data = $this->validated($request, $id);

        if ((string) ($subcategory->division_id ?? '') !== (string) ($data['division_id'] ?? '')
            && $this->isUsed($id)) {
            return response()->json(['message' => 'Scope subkategori yang sudah dipakai ticket tidak dapat diubah.'], 422);
        }

        $subcategory->update($data);

        AuditLogger::log('admin.ticket_subcategory.updated', $subcategory, [
            'before' => $before,
            'after' => $subcategory->fresh()->toArray(),
        ]);

        return response()->json(['data' => $subcategory->load('division:id,name')]);
    }

    public function destroy(string $id)
    {
        $subcategory = TicketSubcategory::query()->findOrFail($id);
        if ($this->isUsed($id)) {
            return response()->json(['message' => 'Subkategori sudah dipakai ticket. Nonaktifkan agar riwayat tetap tersimpan.'], 422);
        }

        $subcategory->delete();
        AuditLogger::log('admin.ticket_subcategory.deleted', $subcategory, ['deleted' => ['name' => $subcategory->name]]);

        return response()->json(['message' => 'Subkategori berhasil dihapus.']);
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        $divisionId = is_string($request->input('division_id')) ? $request->input('division_id') : null;
        $uniqueName = Rule::unique('ticket_subcategories', 'name')
            ->where(fn ($query) => $divisionId
                ? $query->where('division_id', $divisionId)
                : $query->whereNull('division_id'));

        if ($ignoreId) {
            $uniqueName->ignore($ignoreId);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', $uniqueName],
            'division_id' => ['nullable', 'uuid', 'exists:divisions,id'],
            'is_active' => ['required', 'boolean'],
        ]);

        return $data;
    }

    private function isUsed(string $id): bool
    {
        return Ticket::query()
            ->where('global_subcategory_id', $id)
            ->orWhere('division_subcategory_id', $id)
            ->exists();
    }
}
