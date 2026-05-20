<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketTakeoverRequest;
use App\Models\User;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TicketTakeoverRequestService
{
    public function request(User $pic, string $ticketId, string $reason): TicketTakeoverRequest
    {
        if ($pic->role !== 'pic') {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new HttpException(422, 'Alasan wajib diisi.');
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()
            ->with(['assignee' => fn ($q) => $q->withTrashed()])
            ->find($ticketId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        if ((string) $ticket->assigned_to !== (string) $pic->id) {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        if (!in_array((string) $ticket->status, ['open', 'pending', 'on_progress'], true)) {
            throw new HttpException(422, 'Request takeover hanya bisa dilakukan saat ticket aktif.');
        }

        return DB::transaction(function () use ($pic, $ticket, $reason): TicketTakeoverRequest {
            $now = CarbonImmutable::now();

            /** @var TicketTakeoverRequest|null $existing */
            $existing = TicketTakeoverRequest::query()->where('ticket_id', $ticket->id)->first();
            if ($existing && in_array((string) $existing->status, ['pending', 'approved'], true)) {
                throw new HttpException(422, 'Ticket ini sudah memiliki request takeover yang aktif.');
            }

            $request = $existing ?: new TicketTakeoverRequest();
            $request->ticket_id = $ticket->id;
            $request->requested_by = $pic->id;
            $request->reason = $reason;
            $request->status = 'pending';
            $request->approved_by = null;
            $request->rejected_by = null;
            $request->closed_by = null;
            $request->approved_at = null;
            $request->rejected_at = null;
            $request->closed_at = null;
            $request->save();

            AuditLogger::log('ticket.request.created', $ticket, [
                'reason' => $reason,
                'requested_by' => $pic->id,
                'status' => 'pending',
                'requested_at' => $now->toISOString(),
            ], $pic);

            return $request->fresh(['requester' => fn ($q) => $q->withTrashed()]) ?? $request;
        });
    }

    public function cancel(User $pic, string $ticketId): TicketTakeoverRequest
    {
        if ($pic->role !== 'pic') {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->find($ticketId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        /** @var TicketTakeoverRequest|null $request */
        $request = TicketTakeoverRequest::query()->where('ticket_id', $ticket->id)->first();
        if (!$request || (string) $request->status !== 'pending') {
            throw new HttpException(404, 'Request takeover tidak ditemukan.');
        }

        if ((string) $request->requested_by !== (string) $pic->id) {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        return DB::transaction(function () use ($pic, $ticket, $request): TicketTakeoverRequest {
            $now = CarbonImmutable::now();

            $request->status = 'closed';
            $request->closed_by = $pic->id;
            $request->closed_at = $now;
            $request->save();

            AuditLogger::log('ticket.request.canceled', $ticket, [
                'requested_by' => $request->requested_by,
                'closed_by' => $pic->id,
                'closed_at' => $now->toISOString(),
            ], $pic);

            return $request->fresh(['requester' => fn ($q) => $q->withTrashed()]) ?? $request;
        });
    }

    public function approve(User $spv, string $ticketId): TicketTakeoverRequest
    {
        $this->assertSpv($spv);

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->find($ticketId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        /** @var TicketTakeoverRequest|null $request */
        $request = TicketTakeoverRequest::query()->where('ticket_id', $ticket->id)->first();
        if (!$request || (string) $request->status !== 'pending') {
            throw new HttpException(404, 'Request takeover tidak ditemukan.');
        }

        return DB::transaction(function () use ($spv, $ticket, $request): TicketTakeoverRequest {
            $now = CarbonImmutable::now();

            $request->status = 'approved';
            $request->approved_by = $spv->id;
            $request->approved_at = $now;
            $request->rejected_by = null;
            $request->rejected_at = null;
            $request->closed_by = null;
            $request->closed_at = null;
            $request->save();

            AuditLogger::log('ticket.request.approved', $ticket, [
                'requested_by' => $request->requested_by,
                'approved_by' => $spv->id,
                'approved_at' => $now->toISOString(),
            ], $spv);

            return $request->fresh(['requester' => fn ($q) => $q->withTrashed()]) ?? $request;
        });
    }

    public function reject(User $spv, string $ticketId): TicketTakeoverRequest
    {
        $this->assertSpv($spv);

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->find($ticketId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        /** @var TicketTakeoverRequest|null $request */
        $request = TicketTakeoverRequest::query()->where('ticket_id', $ticket->id)->first();
        if (!$request || (string) $request->status !== 'pending') {
            throw new HttpException(404, 'Request takeover tidak ditemukan.');
        }

        return DB::transaction(function () use ($spv, $ticket, $request): TicketTakeoverRequest {
            $now = CarbonImmutable::now();

            $request->status = 'rejected';
            $request->rejected_by = $spv->id;
            $request->rejected_at = $now;
            $request->approved_by = null;
            $request->approved_at = null;
            $request->closed_by = null;
            $request->closed_at = null;
            $request->save();

            AuditLogger::log('ticket.request.rejected', $ticket, [
                'requested_by' => $request->requested_by,
                'rejected_by' => $spv->id,
                'rejected_at' => $now->toISOString(),
            ], $spv);

            return $request->fresh(['requester' => fn ($q) => $q->withTrashed()]) ?? $request;
        });
    }

    public function close(User $spv, string $ticketId): TicketTakeoverRequest
    {
        $this->assertSpv($spv);

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->find($ticketId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        /** @var TicketTakeoverRequest|null $request */
        $request = TicketTakeoverRequest::query()->where('ticket_id', $ticket->id)->first();
        if (!$request || (string) $request->status !== 'approved') {
            throw new HttpException(404, 'Request takeover tidak ditemukan.');
        }

        return DB::transaction(function () use ($spv, $ticket, $request): TicketTakeoverRequest {
            $now = CarbonImmutable::now();

            $request->status = 'closed';
            $request->closed_by = $spv->id;
            $request->closed_at = $now;
            $request->save();

            AuditLogger::log('ticket.request.closed', $ticket, [
                'requested_by' => $request->requested_by,
                'closed_by' => $spv->id,
                'closed_at' => $now->toISOString(),
            ], $spv);

            return $request->fresh(['requester' => fn ($q) => $q->withTrashed()]) ?? $request;
        });
    }

    /**
     * Auto close request takeover saat ticket dipindah tangan.
     */
    public function autoCloseOnReassign(Ticket $ticket, ?User $actor = null): void
    {
        /** @var TicketTakeoverRequest|null $request */
        $request = TicketTakeoverRequest::query()->where('ticket_id', $ticket->id)->first();
        if (!$request || !in_array((string) $request->status, ['pending', 'approved'], true)) {
            return;
        }

        DB::transaction(function () use ($ticket, $request, $actor): void {
            $now = CarbonImmutable::now();

            $request->status = 'closed';
            $request->closed_by = $actor?->id;
            $request->closed_at = $now;
            $request->save();

            AuditLogger::log('ticket.request.auto_closed_reassigned', $ticket, [
                'requested_by' => $request->requested_by,
                'closed_by' => $actor?->id,
                'closed_at' => $now->toISOString(),
            ], $actor);
        });
    }

    private function assertSpv(User $user): void
    {
        if ($user->role !== 'spv') {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }
    }
}

