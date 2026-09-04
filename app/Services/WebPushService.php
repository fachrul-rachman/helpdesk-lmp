<?php

namespace App\Services;

use App\Jobs\SendTicketPushJob;
use App\Models\PushSubscription;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketPushNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebPushService
{
    public static function enabled(): bool
    {
        return (bool) config('webpush.enabled') && config('webpush.public_key') && config('webpush.private_key') && config('webpush.subject')
            && in_array(config('queue.connections.'.config('webpush.connection').'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true);
    }

    public function handle(object $event): void
    {
        if (! self::enabled() || ! ($kind = TicketPushNotification::kind($event)) || ! ($ticketId = $event->data['ticket_id'] ?? null)) {
            return;
        }

        // Retain no chat content in the callback/queue. Run only after business data commits.
        $eventId = hash('sha256', $kind.'|'.$ticketId.'|'.($event->data['id'] ?? $event->data['changed_at'] ?? $event->data['assigned_at'] ?? $event->data['deadline_at'] ?? ''));
        $assignedId = $kind === 'assigned' ? ($event->data['pic_id'] ?? null) : null;
        $deadlineAt = $event->data['deadline_at'] ?? null;
        $enqueue = function () use ($ticketId, $kind, $eventId, $assignedId, $deadlineAt): void {
            try {
                $ticket = Ticket::query()->with('takeoverRequest')->find($ticketId);
                if (! $ticket || ! TicketPushNotification::relevant($ticket, $kind, $deadlineAt)) {
                    return;
                }
                $recipientId = TicketPushNotification::recipientId($ticket);
                $user = $recipientId ? User::query()->where('is_active', true)->whereIn('role', ['pic', 'spv'])->find($recipientId) : null;
                if (! $user || ($kind === 'assigned' && ($user->role !== 'pic' || $user->id !== $assignedId))) {
                    return;
                }

                PushSubscription::query()->where('user_id', $user->id)->pluck('id')->each(function ($subscriptionId) use ($ticketId, $user, $kind, $eventId, $deadlineAt): void {
                    SendTicketPushJob::dispatch((int) $subscriptionId, (string) $ticketId, (string) $user->id, $kind, $eventId, time() + 300, $deadlineAt)
                        ->onConnection(config('webpush.connection'));
                });
            } catch (\Throwable) {
                // An unavailable push queue must never roll back incoming messages or ticket updates.
                Log::warning('webpush.enqueue_failed', ['ticket_id' => $ticketId]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($enqueue);
        } else {
            $enqueue();
        }
    }
}
