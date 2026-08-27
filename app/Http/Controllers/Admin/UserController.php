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
    public function __construct(private readonly AssignService $assignService) {}

    public function index(Request $request)
    {
        $query = User::query()
            ->with(['division:id,name,is_active', 'divisions:id,name,is_active'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        if ($request->filled('division_id')) {
            $query->inDivision((string) $request->string('division_id'));
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
                    'divisions' => $user->divisions->sortBy('name')->map(fn (Division $division) => [
                        'id' => $division->id,
                        'name' => $division->name,
                    ])->values(),
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
            'division_id' => ['nullable', 'uuid', 'exists:divisions,id'],
            'division_ids' => ['nullable', 'array'],
            'division_ids.*' => ['uuid', 'distinct', 'exists:divisions,id'],
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

        $divisionIds = $this->requestedDivisionIds($data, $data['role']);

        if ($data['role'] === 'pic' && $divisionIds === []) {
            return response()->json([
                'message' => 'Data yang diberikan tidak valid.',
                'errors' => [
                    'division_id' => ['Divisi wajib diisi untuk PIC.'],
                    'division_ids' => ['Minimal satu divisi wajib dipilih untuk PIC.'],
                ],
            ], 422);
        }

        $divisionId = $divisionIds[0] ?? null;

        if ($divisionId) {
            /** @var Division|null $division */
            $division = Division::query()->find($divisionId);
            if (! $division) {
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

        $user->divisions()->sync($divisionIds);

        if ($user->role === 'pic') {
            foreach ($divisionIds as $id) {
                $this->syncDivisionIsActive($id);
            }
        }

        AuditLogger::log(
            action: 'admin.user.created',
            subject: $user,
            payload: ['data' => ['name' => $user->name, 'role' => $user->role, 'division_ids' => $divisionIds]],
        );

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'data' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role],
        ], 201);
    }

    public function show(string $id)
    {
        /** @var User|null $user */
        $user = User::query()->with(['division:id,name,is_active', 'divisions:id,name,is_active'])->find($id);
        if (! $user) {
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
                'divisions' => $user->divisions->sortBy('name')->map(fn (Division $division) => [
                    'id' => $division->id,
                    'name' => $division->name,
                    'is_active' => $division->is_active,
                ])->values(),
                'is_active' => $user->is_active,
                'created_at' => optional($user->created_at)->toISOString(),
            ],
        ]);
    }

    public function update(Request $request, string $id)
    {
        /** @var User|null $user */
        $user = User::query()->with(['division:id,name', 'divisions:id,name'])->find($id);
        if (! $user) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'string'],
            'division_id' => ['sometimes', 'nullable', 'uuid', 'exists:divisions,id'],
            'division_ids' => ['sometimes', 'array'],
            'division_ids.*' => ['uuid', 'distinct', 'exists:divisions,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $before = [
            'name' => $user->name,
            'phone_number' => $user->phone_number,
            'division_ids' => $this->divisionIds($user),
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

        $oldDivisionIds = $this->divisionIds($user);
        $hasDivisionUpdate = array_key_exists('division_ids', $data) || array_key_exists('division_id', $data);
        $newDivisionIds = $hasDivisionUpdate
            ? $this->requestedDivisionIds($data, (string) $user->role)
            : $oldDivisionIds;

        if ($user->role === 'pic' && $newDivisionIds === []) {
            return response()->json([
                'message' => 'Minimal satu divisi wajib dipilih untuk PIC.',
                'errors' => ['division_ids' => ['Minimal satu divisi wajib dipilih untuk PIC.']],
            ], 422);
        }

        $removedDivisionIds = array_values(array_diff($oldDivisionIds, $newDivisionIds));
        $willDeactivate = $user->role === 'pic'
            && array_key_exists('is_active', $data)
            && $data['is_active'] === false;

        $division = $this->lastActivePicDivision($user, $willDeactivate ? $oldDivisionIds : $removedDivisionIds);
        if ($division) {
            return response()->json([
                'message' => "Tidak dapat mengubah PIC. User ini adalah satu-satunya PIC aktif di divisi {$division->name}.",
            ], 422);
        }

        if ($willDeactivate) {
            $this->assignService->reassignFromUser($user);
        } elseif ($removedDivisionIds !== []) {
            $this->assignService->reassignFromUser($user, $removedDivisionIds);
        }

        unset($data['division_ids']);
        $data['division_id'] = $user->role === 'pic'
            ? (in_array($user->division_id, $newDivisionIds, true) ? $user->division_id : ($newDivisionIds[0] ?? null))
            : null;
        $user->fill($data)->save();
        $user->divisions()->sync($newDivisionIds);

        if ($user->role === 'pic') {
            foreach (array_unique([...$oldDivisionIds, ...$newDivisionIds]) as $divisionId) {
                $this->syncDivisionIsActive($divisionId);
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
                    'division_ids' => $newDivisionIds,
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
        $user = User::query()->with(['division:id,name', 'divisions:id,name'])->find($id);
        if (! $user) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        if ((string) $request->user()->id === (string) $user->id) {
            return response()->json(['message' => 'Tidak dapat menghapus akun sendiri.'], 422);
        }

        $divisionIds = $this->divisionIds($user);
        $lastActiveDivision = $this->lastActivePicDivision($user, $divisionIds);
        if ($lastActiveDivision) {
            return response()->json([
                'message' => "Tidak dapat menghapus PIC. User ini adalah satu-satunya PIC aktif di divisi {$lastActiveDivision->name}.",
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
        $user->delete();

        foreach ($divisionIds as $divisionId) {
            $this->syncDivisionIsActive($divisionId);
        }

        AuditLogger::log(
            action: 'admin.user.deleted',
            subject: $user,
            payload: ['deleted' => $deletedPayload],
        );

        return response()->json(['message' => 'User berhasil dihapus.'], 200);
    }

    /** @return array<int, string> */
    private function divisionIds(User $user): array
    {
        return collect([$user->division_id])
            ->merge($user->divisions->pluck('id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int, string> $divisionIds */
    private function lastActivePicDivision(User $user, array $divisionIds): ?Division
    {
        if ($user->role !== 'pic' || ! $user->is_active) {
            return null;
        }

        foreach ($divisionIds as $divisionId) {
            $hasOtherPic = User::query()
                ->where('id', '!=', $user->id)
                ->where('role', 'pic')
                ->where('is_active', true)
                ->inDivision($divisionId)
                ->exists();

            if (! $hasOtherPic) {
                return Division::query()->find($divisionId);
            }
        }

        return null;
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
        if (! $division) {
            return;
        }

        if ($division->is_fallback) {
            Division::query()->where('id', $divisionId)->update(['is_active' => true]);

            return;
        }

        $hasActivePic = User::query()
            ->where('role', 'pic')
            ->inDivision($divisionId)
            ->where('is_active', true)
            ->exists();

        Division::query()
            ->where('id', $divisionId)
            ->update(['is_active' => $hasActivePic]);
    }

    /** @return array<int, string> */
    private function requestedDivisionIds(array $data, string $role): array
    {
        if ($role !== 'pic') {
            return [];
        }

        $ids = array_key_exists('division_ids', $data)
            ? ($data['division_ids'] ?? [])
            : [($data['division_id'] ?? null)];

        return array_values(array_unique(array_filter($ids)));
    }
}
