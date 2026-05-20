<?php

namespace App\Jobs;

use App\Events\SlaWarning;
use App\Models\AppSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckSlaFrReminderJob implements ShouldQueue
{
    use Queueable;

    public function handle(NotificationService $notificationService): void
    {
        $now = CarbonImmutable::now();
        $reminderMinutes = max(1, (int) (AppSetting::query()->find('sla_fr_reminder_minutes')?->value ?? 3));

        Ticket::query()
            ->with(['customer' => fn ($q) => $q->withTrashed(), 'division', 'assignee' => fn ($q) => $q->withTrashed()])
            ->where('status', 'new')
            ->where('sla_fr_status', 'running')
            ->whereNotNull('sla_fr_deadline_at')
            ->whereNotNull('assigned_to')
            ->chunkById(200, function ($tickets) use ($notificationService, $now, $reminderMinutes) {
                foreach ($tickets as $ticket) {
                    try {
                        $deadline = $ticket->sla_fr_deadline_at ? CarbonImmutable::parse($ticket->sla_fr_deadline_at) : null;
                        if (!$deadline || $deadline->lessThanOrEqualTo($now)) {
                            continue;
                        }

                        $minutesLeft = $now->diffInMinutes($deadline);
                        if ($minutesLeft > $reminderMinutes) {
                            continue;
                        }

                        $already = DB::table('audit_logs')
                            ->where('action', 'ticket.sla_fr_reminder_sent')
                            ->where('subject_type', 'Ticket')
                            ->where('subject_id', $ticket->id)
                            ->whereDate('created_at', $now->toDateString())
                            ->exists();

                        if ($already) {
                            continue;
                        }

                        $pic = User::query()->find((string) $ticket->assigned_to);
                        if ($pic && $pic->phone_number) {
                            $notificationService->sendPicSlaFrWarning($pic, $ticket, $minutesLeft);
                        }

                        event(new SlaWarning([
                            'ticket_id' => $ticket->id,
                            'ticket_subject' => $ticket->subject,
                            'sla_type' => 'fr',
                            'warning_type' => 'reminder',
                            'deadline_at' => $deadline->toISOString(),
                            'pic_id' => (string) ($ticket->assigned_to ?? ''),
                        ]));

                        DB::table('audit_logs')->insert([
                            'user_id' => null,
                            'action' => 'ticket.sla_fr_reminder_sent',
                            'subject_type' => 'Ticket',
                            'subject_id' => $ticket->id,
                            'payload' => null,
                            'ip_address' => null,
                            'created_at' => $now,
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('sla.fr.reminder.failed', ['ticket_id' => $ticket->id ?? null, 'error' => $e->getMessage()]);
                    }
                }
            });
    }
}
