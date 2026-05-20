<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\NotificationService;
use App\Services\TicketService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckPendingTicketsJob implements ShouldQueue
{
    use Queueable;

    public function handle(TicketService $ticketService, NotificationService $notificationService): void
    {
        $now = CarbonImmutable::now();

        Ticket::query()
            ->where('status', 'pending')
            ->whereNotNull('sla_resolution_paused_at')
            ->chunkById(200, function ($tickets) use ($ticketService, $notificationService, $now) {
                foreach ($tickets as $ticket) {
                    try {
                        $this->handleTicket($ticketService, $notificationService, $ticket, $now);
                    } catch (\Throwable $e) {
                        Log::warning('pending_ticket.check_failed', [
                            'ticket_id' => $ticket->id ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function handleTicket(
        TicketService $ticketService,
        NotificationService $notificationService,
        Ticket $ticket,
        CarbonImmutable $now,
    ): void
    {
        $pendingStartedAt = $ticket->sla_resolution_paused_at ? CarbonImmutable::parse($ticket->sla_resolution_paused_at) : null;
        if (!$pendingStartedAt) {
            return;
        }

        $lastCustomerMessageAt = DB::table('messages')
            ->where('ticket_id', $ticket->id)
            ->where('sender_type', 'customer')
            ->max('created_at');

        if (is_string($lastCustomerMessageAt) && $lastCustomerMessageAt !== '') {
            $lastCustomerMessageAt = CarbonImmutable::parse($lastCustomerMessageAt);
            if ($lastCustomerMessageAt->greaterThan($pendingStartedAt)) {
                return;
            }
        }

        $hours = $pendingStartedAt->diffInHours($now);

        // 23 jam: reminder sekali
        if ($hours >= 23 && $pendingStartedAt->diffInHours($now) < 24) {
            $already = DB::table('audit_logs')
                ->where('action', 'ticket.pending_reminder_sent')
                ->where('subject_type', 'Ticket')
                ->where('subject_id', $ticket->id)
                ->where('created_at', '>=', $pendingStartedAt)
                ->exists();

            if (!$already) {
                $ticket->loadMissing(['customer' => fn ($q) => $q->withTrashed()]);
                $notificationService->sendCustomerPendingReminder($ticket, 60);

                DB::table('audit_logs')->insert([
                    'user_id' => null,
                    'action' => 'ticket.pending_reminder_sent',
                    'subject_type' => 'Ticket',
                    'subject_id' => $ticket->id,
                    'payload' => null,
                    'ip_address' => null,
                    'created_at' => $now,
                ]);
            }

            return;
        }

        // 24 jam: closed otomatis
        if ($pendingStartedAt->diffInHours($now) >= 24) {
            $ticketService->closePendingTicketBySystem($ticket);
        }
    }
}
