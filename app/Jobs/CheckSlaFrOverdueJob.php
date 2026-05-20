<?php

namespace App\Jobs;

use App\Events\SlaWarning;
use App\Models\Ticket;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckSlaFrOverdueJob implements ShouldQueue
{
    use Queueable;

    public function handle(NotificationService $notificationService): void
    {
        $now = CarbonImmutable::now();

        Ticket::query()
            ->with(['customer' => fn ($q) => $q->withTrashed(), 'division', 'assignee' => fn ($q) => $q->withTrashed()])
            ->where('status', 'new')
            ->where('sla_fr_status', 'running')
            ->whereNotNull('sla_fr_deadline_at')
            ->chunkById(200, function ($tickets) use ($notificationService, $now) {
                foreach ($tickets as $ticket) {
                    try {
                        $deadline = $ticket->sla_fr_deadline_at ? CarbonImmutable::parse($ticket->sla_fr_deadline_at) : null;
                        if (!$deadline || $deadline->greaterThan($now)) {
                            continue;
                        }

                        $already = DB::table('audit_logs')
                            ->where('action', 'ticket.sla_fr_overdue_sent')
                            ->where('subject_type', 'Ticket')
                            ->where('subject_id', $ticket->id)
                            ->exists();
                        if ($already) {
                            continue;
                        }

                        $ticket->sla_fr_status = 'overdue';
                        $ticket->save();

                        $spv = User::query()->where('role', 'spv')->first();
                        if ($spv && $spv->phone_number) {
                            $notificationService->sendSpvSlaFrOverdue($spv, $ticket);
                        }

                        event(new SlaWarning([
                            'ticket_id' => $ticket->id,
                            'ticket_subject' => $ticket->subject,
                            'sla_type' => 'fr',
                            'warning_type' => 'overdue',
                            'deadline_at' => $deadline->toISOString(),
                            'pic_id' => (string) ($ticket->assigned_to ?? ''),
                        ]));

                        DB::table('audit_logs')->insert([
                            'user_id' => null,
                            'action' => 'ticket.sla_fr_overdue_sent',
                            'subject_type' => 'Ticket',
                            'subject_id' => $ticket->id,
                            'payload' => null,
                            'ip_address' => null,
                            'created_at' => $now,
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('sla.fr.overdue.failed', ['ticket_id' => $ticket->id ?? null, 'error' => $e->getMessage()]);
                    }
                }
            });
    }
}
