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

class CheckSlaResolutionReminderJob implements ShouldQueue
{
    use Queueable;

    public function handle(NotificationService $notificationService): void
    {
        $now = CarbonImmutable::now();

        Ticket::query()
            ->with([
                'division:id,name,sla_resolution_reminder_value,sla_resolution_reminder_unit',
                'customer' => fn ($q) => $q->withTrashed(),
                'assignee' => fn ($q) => $q->withTrashed(),
            ])
            ->where('sla_resolution_status', 'running')
            ->whereNotNull('sla_resolution_deadline_at')
            ->whereNotNull('assigned_to')
            ->chunkById(200, function ($tickets) use ($notificationService, $now) {
                foreach ($tickets as $ticket) {
                    try {
                        $deadline = $ticket->sla_resolution_deadline_at ? CarbonImmutable::parse($ticket->sla_resolution_deadline_at) : null;
                        if (!$deadline || $deadline->lessThanOrEqualTo($now)) {
                            continue;
                        }

                        $reminderMinutes = 60;
                        $value = (int) ($ticket->division?->sla_resolution_reminder_value ?? 12);
                        $unit = (string) ($ticket->division?->sla_resolution_reminder_unit ?? 'hours');
                        $reminderMinutes = $unit === 'days' ? $value * 60 * 24 : $value * 60;

                        $minutesLeft = $now->diffInMinutes($deadline);
                        if ($minutesLeft > $reminderMinutes) {
                            continue;
                        }

                        $already = DB::table('audit_logs')
                            ->where('action', 'ticket.sla_resolution_reminder_sent')
                            ->where('subject_type', 'Ticket')
                            ->where('subject_id', $ticket->id)
                            ->whereDate('created_at', $now->toDateString())
                            ->exists();

                        if ($already) {
                            continue;
                        }

                        $pic = User::query()->find((string) $ticket->assigned_to);
                        if ($pic && $pic->phone_number) {
                            $notificationService->sendPicSlaResolutionWarning($pic, $ticket, $deadline);
                        }

                        event(new SlaWarning([
                            'ticket_id' => $ticket->id,
                            'ticket_subject' => $ticket->subject,
                            'sla_type' => 'resolution',
                            'warning_type' => 'reminder',
                            'deadline_at' => $deadline->toISOString(),
                            'pic_id' => (string) ($ticket->assigned_to ?? ''),
                        ]));

                        DB::table('audit_logs')->insert([
                            'user_id' => null,
                            'action' => 'ticket.sla_resolution_reminder_sent',
                            'subject_type' => 'Ticket',
                            'subject_id' => $ticket->id,
                            'payload' => null,
                            'ip_address' => null,
                            'created_at' => $now,
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('sla.resolution.reminder.failed', ['ticket_id' => $ticket->id ?? null, 'error' => $e->getMessage()]);
                    }
                }
            });
    }
}
