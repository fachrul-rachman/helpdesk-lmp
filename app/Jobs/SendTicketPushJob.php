<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\RefreshToken;
use App\Models\Ticket;
use App\Models\User;
use App\Services\WebPushService;
use App\Support\PushEndpoint;
use App\Support\TicketPushNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class SendTicketPushJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(
        public int $subscriptionId,
        public string $ticketId,
        public string $userId,
        public string $kind,
        public string $eventId,
        public int $expiresAt,
        public ?string $deadlineAt = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->subscriptionId.'-'.$this->eventId;
    }

    public function backoff(): array
    {
        return [20, 60];
    }

    public function handle(): void
    {
        if (! WebPushService::enabled() || time() >= $this->expiresAt) {
            return;
        }
        $subscription = PushSubscription::query()->where('user_id', $this->userId)->find($this->subscriptionId);
        $ticket = Ticket::query()->with('takeoverRequest')->find($this->ticketId);
        $user = User::query()->where('is_active', true)->whereIn('role', ['pic', 'spv'])->find($this->userId);
        if (! $subscription || ! $ticket || ! $user || TicketPushNotification::recipientId($ticket) !== $this->userId
            || ! TicketPushNotification::relevant($ticket, $this->kind, $this->deadlineAt)
            || ($this->kind === 'assigned' && $user->role !== 'pic')
            || ! hash_equals($subscription->vapid_key_hash, hash('sha256', config('webpush.public_key')))
            || ! PushEndpoint::allowed($subscription->endpoint)) {
            return;
        }

        if (! RefreshToken::query()->where('user_id', $this->userId)->whereNull('revoked_at')->where('expires_at', '>', now())->whereKey($subscription->refresh_token_id)->exists()) {
            $subscription->delete();

            return;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('webpush.subject'),
                'publicKey' => config('webpush.public_key'),
                'privateKey' => config('webpush.private_key'),
            ]], ['TTL' => max(1, $this->expiresAt - time())], 15, ['allow_redirects' => false]);
            $report = $webPush->sendOneNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'keys' => ['p256dh' => $subscription->public_key, 'auth' => $subscription->auth_token],
            ]), json_encode(TicketPushNotification::payload($ticket, $user->role, $this->kind, $this->eventId) + ['user_id' => $this->userId], JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // Never persist provider URLs, encryption keys, or raw HTTP exceptions in failed_jobs.
            throw new \RuntimeException('Web Push delivery failed.');
        }

        if ($report->isSubscriptionExpired()) {
            $subscription->delete();
        } elseif (! $report->isSuccess()) {
            $status = $report->getResponse()?->getStatusCode();
            if ($status === null || $status === 429 || $status >= 500) {
                throw new \RuntimeException('Web Push temporarily unavailable.');
            }
            Log::warning('webpush.delivery_rejected', ['subscription_id' => $subscription->id, 'status' => $status]);
        }
    }
}
