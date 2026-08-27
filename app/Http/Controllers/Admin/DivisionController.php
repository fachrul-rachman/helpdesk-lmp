<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\DivisionWorkingHour;
use App\Models\Ticket;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class DivisionController extends Controller
{
    public function index()
    {
        $divisions = Division::query()
            ->with(['workingHours'])
            ->withCount(['users as pic_count' => fn ($query) => $query
                ->where('role', 'pic')
                ->where('is_active', true)])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $divisions->map(function (Division $division) {
                return [
                    'id' => $division->id,
                    'name' => $division->name,
                    'description' => $division->description,
                    'handles' => $division->handles,
                    'not_handles' => $division->not_handles,
                    'ticket_examples' => $division->ticket_examples,
                    'sla_resolution_value' => $division->sla_resolution_value,
                    'sla_resolution_unit' => $division->sla_resolution_unit,
                    'sla_resolution_reminder_value' => $division->sla_resolution_reminder_value,
                    'sla_resolution_reminder_unit' => $division->sla_resolution_reminder_unit,
                    'is_fallback' => $division->is_fallback,
                    'is_active' => $division->is_active,
                    'pic_count' => (int) $division->pic_count,
                    'working_hours' => $division->workingHours
                        ->sortBy(fn (DivisionWorkingHour $wh) => $this->dayIndex($wh->day_of_week))
                        ->values()
                        ->map(fn (DivisionWorkingHour $wh) => [
                            'day_of_week' => $wh->day_of_week,
                            'start_time' => substr((string) $wh->start_time, 0, 5),
                            'end_time' => substr((string) $wh->end_time, 0, 5),
                            'is_active' => $wh->is_active,
                        ])
                        ->all(),
                ];
            })->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateDivision($request);

        if (! empty($data['is_fallback']) && Division::query()->where('is_fallback', true)->exists()) {
            return response()->json(['message' => 'Sudah ada divisi fallback. Hanya boleh ada 1 divisi fallback.'], 422);
        }

        $division = Division::create(Arr::except($data, ['working_hours']) + [
            'is_active' => false,
        ]);

        if ($division->is_fallback) {
            $division->update(['is_active' => true]);
        }

        $this->syncWorkingHours($division, $data['working_hours']);

        AuditLogger::log(
            action: 'admin.division.created',
            subject: $division,
            payload: ['data' => Arr::except($division->toArray(), ['deleted_at'])],
        );

        return response()->json(['message' => 'Divisi berhasil dibuat.', 'data' => ['id' => $division->id]], 201);
    }

    public function update(Request $request, string $id)
    {
        /** @var Division|null $division */
        $division = Division::query()->with('workingHours')->find($id);
        if (! $division) {
            return response()->json(['message' => 'Divisi tidak ditemukan.'], 404);
        }

        $before = $division->toArray();

        $data = $this->validateDivision($request);

        if (! empty($data['is_fallback']) && ! $division->is_fallback) {
            if (Division::query()->where('is_fallback', true)->where('id', '!=', $division->id)->exists()) {
                return response()->json(['message' => 'Sudah ada divisi fallback. Hanya boleh ada 1 divisi fallback.'], 422);
            }
        }

        $division->fill(Arr::except($data, ['working_hours']))->save();

        $this->syncWorkingHours($division, $data['working_hours']);

        if ($division->is_fallback) {
            $division->update(['is_active' => true]);
        }

        AuditLogger::log(
            action: 'admin.division.updated',
            subject: $division,
            payload: [
                'before' => $before,
                'after' => $division->fresh()->toArray(),
            ],
        );

        return response()->json(['message' => 'Divisi berhasil diupdate.'], 200);
    }

    public function destroy(string $id)
    {
        /** @var Division|null $division */
        $division = Division::query()->find($id);
        if (! $division) {
            return response()->json(['message' => 'Divisi tidak ditemukan.'], 404);
        }

        if ($division->is_fallback) {
            return response()->json(['message' => 'Tidak dapat menghapus divisi fallback.'], 422);
        }

        $activeTickets = Ticket::query()
            ->where('division_id', $division->id)
            ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
            ->count();

        if ($activeTickets > 0) {
            return response()->json(['message' => "Divisi masih memiliki {$activeTickets} ticket aktif."], 422);
        }

        $division->delete();

        AuditLogger::log(
            action: 'admin.division.deleted',
            subject: $division,
            payload: ['deleted' => ['name' => $division->name]],
        );

        return response()->json(['message' => 'Divisi berhasil dihapus.'], 200);
    }

    private function validateDivision(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'handles' => ['required', 'string'],
            'not_handles' => ['required', 'string'],
            'ticket_examples' => ['required', 'string'],
            'sla_resolution_value' => ['required', 'integer', 'min:1'],
            'sla_resolution_unit' => ['required', 'in:hours,days'],
            'sla_resolution_reminder_value' => ['required', 'integer', 'min:1'],
            'sla_resolution_reminder_unit' => ['required', 'in:hours,days'],
            'is_fallback' => ['sometimes', 'boolean'],
            'working_hours' => ['sometimes', 'array', 'size:7'],
            'working_hours.*.day_of_week' => ['required', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'working_hours.*.start_time' => ['required', 'date_format:H:i'],
            'working_hours.*.end_time' => ['required', 'date_format:H:i'],
            'working_hours.*.is_active' => ['required', 'boolean'],
        ], [
            'working_hours.size' => 'working_hours harus berisi tepat 7 hari.',
        ]);

        if (! isset($data['working_hours'])) {
            $data['working_hours'] = [
                ['day_of_week' => 'monday', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true],
                ['day_of_week' => 'tuesday', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true],
                ['day_of_week' => 'wednesday', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true],
                ['day_of_week' => 'thursday', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true],
                ['day_of_week' => 'friday', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true],
                ['day_of_week' => 'saturday', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => false],
                ['day_of_week' => 'sunday', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => false],
            ];
        } else {
            $uniqueDays = collect($data['working_hours'])->pluck('day_of_week')->unique()->count();
            if ($uniqueDays !== 7) {
                throw ValidationException::withMessages([
                    'working_hours' => ['working_hours harus berisi semua hari dalam seminggu.'],
                ]);
            }
        }

        $data['is_fallback'] = (bool) ($data['is_fallback'] ?? false);

        return $data;
    }

    private function syncWorkingHours(Division $division, array $workingHours): void
    {
        DivisionWorkingHour::query()->where('division_id', $division->id)->delete();

        foreach ($workingHours as $wh) {
            DivisionWorkingHour::create([
                'division_id' => $division->id,
                'day_of_week' => $wh['day_of_week'],
                'start_time' => $wh['start_time'],
                'end_time' => $wh['end_time'],
                'is_active' => (bool) $wh['is_active'],
            ]);
        }
    }

    private function dayIndex(string $day): int
    {
        return match ($day) {
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            'sunday' => 7,
            default => 99,
        };
    }
}
