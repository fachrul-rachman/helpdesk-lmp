<?php

namespace App\Services;

use App\Events\TicketAssigned;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AssignService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly TicketTakeoverRequestService $takeoverRequestService,
    ) {
    }

    public function autoAssign(Ticket $ticket): ?User
    {
        /** @var Division|null $division */
        $division = Division::query()->find($ticket->division_id);
        if (!$division) {
            throw new HttpException(404, 'Divisi tidak ditemukan.');
        }

        if ($division->is_fallback) {
            return null;
        }

        if (!$division->is_active) {
            $this->redirectToFallback($ticket, 'division_inactive');
            return null;
        }

        $candidatePics = User::query()
            ->where('role', 'pic')
            ->where('division_id', $division->id)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        if (count($candidatePics) === 0) {
            Log::warning('assign.no_pic_in_division', ['ticket_id' => $ticket->id, 'division_id' => $division->id]);
            $this->redirectToFallback($ticket, 'no_pic_in_division');
            return null;
        }

        $counts = Ticket::query()
            ->selectRaw('assigned_to, COUNT(*) as cnt')
            ->whereIn('assigned_to', $candidatePics)
            ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
            ->groupBy('assigned_to')
            ->pluck('cnt', 'assigned_to');

        $minCount = null;
        $best = [];
        foreach ($candidatePics as $picId) {
            $cnt = (int) ($counts[$picId] ?? 0);
            if ($minCount === null || $cnt < $minCount) {
                $minCount = $cnt;
                $best = [$picId];
                continue;
            }
            if ($cnt === $minCount) {
                $best[] = $picId;
            }
        }

        shuffle($best);
        $pickedId = (string) ($best[0] ?? '');
        if ($pickedId === '') {
            return null;
        }

        /** @var User|null $picked */
        $picked = User::query()->find($pickedId);
        if (!$picked) {
            return null;
        }

        $ticket->assigned_to = $picked->id;
        $ticket->save();

        $this->takeoverRequestService->autoCloseOnReassign($ticket);

        event(new TicketAssigned([
            'ticket_id' => $ticket->id,
            'assigned_to' => ['id' => $picked->id, 'name' => $picked->name],
            'assigned_by' => ['id' => null, 'name' => 'Sistem'],
            'assigned_at' => CarbonImmutable::now()->toISOString(),
            'pic_id' => $picked->id,
        ]));

        $ticket->loadMissing(['customer', 'division']);
        $this->notificationService->sendTicketAssignedToAgent($picked, $ticket);

        return $picked;
    }

    public function reassignFromUser(User $user): void
    {
        if ($user->role !== 'pic') {
            return;
        }

        $tickets = Ticket::query()
            ->where('assigned_to', $user->id)
            ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
            ->get();

        $divisionIds = [];

        foreach ($tickets as $ticket) {
            $divisionIds[] = (string) $ticket->division_id;

            // Pastikan divisi aktif; jika tidak, redirect fallback.
            $division = Division::query()->find($ticket->division_id);
            if ($division && !$division->is_fallback && !$division->is_active) {
                $this->redirectToFallback($ticket, 'division_inactive');
                continue;
            }

            // Assign ulang ke PIC lain di divisi yang sama (exclude user ini).
            if ($division && !$division->is_fallback) {
                $candidatePics = User::query()
                    ->where('role', 'pic')
                    ->where('division_id', $division->id)
                    ->where('is_active', true)
                    ->where('id', '!=', $user->id)
                    ->pluck('id')
                    ->all();

                if (count($candidatePics) === 0) {
                    $this->redirectToFallback($ticket, 'no_pic_in_division');
                    continue;
                }

                $counts = Ticket::query()
                    ->selectRaw('assigned_to, COUNT(*) as cnt')
                    ->whereIn('assigned_to', $candidatePics)
                    ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
                    ->groupBy('assigned_to')
                    ->pluck('cnt', 'assigned_to');

                $minCount = null;
                $best = [];
                foreach ($candidatePics as $picId) {
                    $cnt = (int) ($counts[$picId] ?? 0);
                    if ($minCount === null || $cnt < $minCount) {
                        $minCount = $cnt;
                        $best = [$picId];
                        continue;
                    }
                    if ($cnt === $minCount) {
                        $best[] = $picId;
                    }
                }

                shuffle($best);
                $pickedId = (string) ($best[0] ?? '');
                if ($pickedId === '') {
                    $ticket->assigned_to = null;
                    $ticket->save();
                    continue;
                }

                $ticket->assigned_to = $pickedId;
                $ticket->save();

                $this->takeoverRequestService->autoCloseOnReassign($ticket);

                $picked = User::query()->find($pickedId);
                event(new TicketAssigned([
                    'ticket_id' => $ticket->id,
                    'assigned_to' => $picked ? ['id' => $picked->id, 'name' => $picked->name] : ['id' => $pickedId],
                    'assigned_by' => ['id' => null, 'name' => 'Sistem'],
                    'assigned_at' => CarbonImmutable::now()->toISOString(),
                    'pic_id' => $pickedId,
                ]));

                if ($picked) {
                    $ticket->loadMissing(['customer', 'division']);
                    $this->notificationService->sendTicketAssignedToAgent($picked, $ticket);
                }
            }
        }

        foreach (array_unique($divisionIds) as $divisionId) {
            $this->syncDivisionIsActive($divisionId);
        }
    }

    private function redirectToFallback(Ticket $ticket, string $reason): void
    {
        /** @var Division|null $fallback */
        $fallback = Division::query()->where('is_fallback', true)->first();
        if (!$fallback) {
            throw new HttpException(500, 'Terjadi kesalahan pada server.');
        }

        $spv = User::query()->where('role', 'spv')->first();

        Log::warning('assign.redirect_to_fallback', [
            'reason' => $reason,
            'ticket_id' => $ticket->id,
            'from_division_id' => $ticket->division_id,
            'fallback_division_id' => $fallback->id,
        ]);

        $ticket->division_id = $fallback->id;
        $ticket->assigned_to = $spv?->id;
        $ticket->save();

        $this->takeoverRequestService->autoCloseOnReassign($ticket);

        if ($ticket->assigned_to) {
            event(new TicketAssigned([
                'ticket_id' => $ticket->id,
                'assigned_to' => ['id' => $ticket->assigned_to],
                'assigned_by' => ['id' => null, 'name' => 'Sistem'],
                'assigned_at' => CarbonImmutable::now()->toISOString(),
                'pic_id' => (string) $ticket->assigned_to,
            ]));

            if ($spv && $spv->phone_number) {
                $ticket->setRelation('division', $fallback);
                $ticket->loadMissing(['customer']);
                $this->notificationService->sendTicketAssignedToAgent($spv, $ticket);
            }
        }
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
