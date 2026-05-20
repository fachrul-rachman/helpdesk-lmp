<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AssignService;
use App\Support\AuditLogger;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct(private readonly AssignService $assignService)
    {
    }

    public function index(Request $request)
    {
        $query = User::query()
            ->with(['division:id,name,is_active'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        if ($request->filled('division_id')) {
            $query->where('division_id', $request->string('division_id'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('phone_number', 'ilike', "%{$search}%");
            });
        }

        $perPage = (int) ($request->input('per_page') ?? 20);
        $users = $query->paginate($perPage);

        return response()->json([
            'data' => $users->getCollection()->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone_number' => $user->phone_number,
                    'role' => $user->role,
                    'division' => $user->division ? [
                        'id' => $user->division->id,
                        'name' => $user->division->name,
                    ] : null,
                    'is_active' => $user->is_active,
                    'created_at' => optional($user->created_at)->toISOString(),
                ];
            })->values(),
            'meta' => [
                'total' => $users->total(),
                'page' => $users->currentPage(),
                'per_page' => $users->perPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string'],
            'role' => ['required', 'in:admin,pic'],
            'division_id' => ['nullable', 'uuid'],
            'password' => ['required', 'string', 'min:8'],
        ], [
            'role.in' => 'Role hanya boleh admin atau pic.',
            'password.min' => 'Password minimal 8 karakter.',
        ]);

        $normalizedPhone = PhoneNumber::normalize($data['phone_number']);

        if (User::query()->where('phone_number', $normalizedPhone)->exists()) {
            return response()->json([
                'message' => 'Nomor HP sudah digunakan.',
                'errors' => ['phone_number' => ['Nomor HP sudah digunakan.']],
            ], 422);
        }

        if ($data['role'] === 'pic' && empty($data['division_id'])) {
            return response()->json([
                'message' => 'Data yang diberikan tidak valid.',
                'errors' => ['division_id' => ['Divisi wajib diisi untuk PIC.']],
            ], 422);
        }

        $divisionId = $data['role'] === 'pic' ? $data['division_id'] : null;

        if ($divisionId) {
            /** @var Division|null $division */
            $division = Division::query()->find($divisionId);
            if (!$division) {
                return response()->json(['message' => 'Divisi tidak ditemukan.'], 404);
            }
        }

        $user = User::create([
            'name' => $data['name'],
            'phone_number' => $normalizedPhone,
            'role' => $data['role'],
            'division_id' => $divisionId,
            'password' => Hash::make($data['password']),
            'is_active' => true,
        ]);

        if ($user->role === 'pic' && $user->division_id) {
            $this->syncDivisionIsActive($user->division_id);
        }

        AuditLogger::log(
            action: 'admin.user.created',
            subject: $user,
            payload: ['data' => ['name' => $user->name, 'role' => $user->role, 'division_id' => $user->division_id]],
        );

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'data' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role],
        ], 201);
    }

    public function show(string $id)
    {
        /** @var User|null $user */
        $user = User::query()->with(['division:id,name,is_active'])->find($id);
        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone_number' => $user->phone_number,
                'role' => $user->role,
                'division' => $user->division ? [
                    'id' => $user->division->id,
                    'name' => $user->division->name,
                    'is_active' => $user->division->is_active,
                ] : null,
                'is_active' => $user->is_active,
                'created_at' => optional($user->created_at)->toISOString(),
            ],
        ]);
    }

    public function update(Request $request, string $id)
    {
        /** @var User|null $user */
        $user = User::query()->with('division:id,name')->find($id);
        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'string'],
            'division_id' => ['sometimes', 'nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $before = [
            'name' => $user->name,
            'phone_number' => $user->phone_number,
            'division_id' => $user->division_id,
            'is_active' => $user->is_active,
        ];

        if (array_key_exists('phone_number', $data)) {
            $normalizedPhone = PhoneNumber::normalize($data['phone_number']);
            $exists = User::query()
                ->where('phone_number', $normalizedPhone)
                ->where('id', '!=', $user->id)
                ->exists();
            if ($exists) {
                return response()->json([
                    'message' => 'Nomor HP sudah digunakan.',
                    'errors' => ['phone_number' => ['Nomor HP sudah digunakan.']],
                ], 422);
            }
            $data['phone_number'] = $normalizedPhone;
        }

        $oldDivisionId = $user->division_id;

        if (array_key_exists('division_id', $data)) {
            if ($user->role !== 'pic') {
                $data['division_id'] = null;
            } elseif ($data['division_id']) {
                $division = Division::query()->find($data['division_id']);
                if (!$division) {
                    return response()->json(['message' => 'Divisi tidak ditemukan.'], 404);
                }
            }
        }

        if ($user->role === 'pic' && array_key_exists('is_active', $data) && $data['is_active'] === false) {
            if ($this->isLastActivePicInDivision($user)) {
                $divisionName = optional($user->division)->name ?? 'divisi';
                return response()->json([
                    'message' => "Tidak dapat menonaktifkan PIC. User ini adalah satu-satunya PIC aktif di divisi {$divisionName}.",
                ], 422);
            }

            $this->assignService->reassignFromUser($user);
        }

        $user->fill($data)->save();

        if ($user->role === 'pic') {
            if ($oldDivisionId) {
                $this->syncDivisionIsActive($oldDivisionId);
            }
            if ($user->division_id) {
                $this->syncDivisionIsActive($user->division_id);
            }
        }

        AuditLogger::log(
            action: 'admin.user.updated',
            subject: $user,
            payload: [
                'before' => $before,
                'after' => [
                    'name' => $user->name,
                    'phone_number' => $user->phone_number,
                    'division_id' => $user->division_id,
                    'is_active' => $user->is_active,
                ],
            ],
        );

        return response()->json([
            'message' => 'User berhasil diupdate.',
            'data' => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        /** @var User|null $user */
        $user = User::query()->with('division:id,name')->find($id);
        if (!$user) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        if ((string) $request->user()->id === (string) $user->id) {
            return response()->json(['message' => 'Tidak dapat menghapus akun sendiri.'], 422);
        }

        if ($user->role === 'pic' && $this->isLastActivePicInDivision($user)) {
            $divisionName = optional($user->division)->name ?? 'divisi';
            return response()->json([
                'message' => "Tidak dapat menghapus PIC. User ini adalah satu-satunya PIC aktif di divisi {$divisionName}.",
            ], 422);
        }

        $activeTicketCount = $this->activeTicketsCountForUser($user);
        if ($activeTicketCount > 0) {
            return response()->json(['message' => "User masih memiliki {$activeTicketCount} ticket aktif."], 422);
        }

        if ($user->role === 'pic') {
            $this->assignService->reassignFromUser($user);
        }

        $deletedPayload = ['name' => $user->name, 'role' => $user->role];
        $divisionId = $user->division_id;

        $user->delete();

        if ($divisionId) {
            $this->syncDivisionIsActive($divisionId);
        }

        AuditLogger::log(
            action: 'admin.user.deleted',
            subject: $user,
            payload: ['deleted' => $deletedPayload],
        );

        return response()->json(['message' => 'User berhasil dihapus.'], 200);
    }

    private function isLastActivePicInDivision(User $user): bool
    {
        if ($user->role !== 'pic' || !$user->division_id || !$user->is_active) {
            return false;
        }

        $activePicCount = User::query()
            ->where('role', 'pic')
            ->where('division_id', $user->division_id)
            ->where('is_active', true)
            ->count();

        return $activePicCount <= 1;
    }

    private function activeTicketsCountForUser(User $user): int
    {
        return Ticket::query()
            ->where('assigned_to', $user->id)
            ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
            ->count();
    }

    private function syncDivisionIsActive(string $divisionId): void
    {
        $division = Division::query()->select(['id', 'is_fallback'])->find($divisionId);
        if (!$division) {
            return;
        }

        if ($division->is_fallback) {
            Division::query()->where('id', $divisionId)->update(['is_active' => true]);
            return;
        }

        $hasActivePic = User::query()
            ->where('role', 'pic')
            ->where('division_id', $divisionId)
            ->where('is_active', true)
            ->exists();

        Division::query()
            ->where('id', $divisionId)
            ->update(['is_active' => $hasActivePic]);
    }
}
