<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DebouncedForwardToN8nJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $cacheKey)
    {
    }

    public function handle(NotificationService $notificationService): void
    {
        $debounceSeconds = (int) config('services.n8n.debounce_seconds', 5);
        $debounceSeconds = max(1, min(60, $debounceSeconds));

        /** @var array<string, mixed>|null $pending */
        $pending = Cache::get($this->cacheKey);
        if (!$pending) {
            return;
        }

        $lastUpdatedAt = $pending['last_updated_at'] ?? null;
        $lastUpdated = is_string($lastUpdatedAt) ? CarbonImmutable::parse($lastUpdatedAt) : null;

        if ($lastUpdated && $lastUpdated->diffInSeconds(CarbonImmutable::now()) < $debounceSeconds) {
            self::dispatch($this->cacheKey)->delay(now()->addSeconds($debounceSeconds));
            return;
        }

        Cache::forget($this->cacheKey);

        $url = (string) config('services.n8n.webhook_url', '');
        $secret = (string) config('services.n8n.secret', '');
        if ($url === '' || $secret === '') {
            Log::warning('n8n.webhook.missing_config');
            return;
        }

        $event = (string) ($pending['event'] ?? '');
        $customer = $pending['customer'] ?? null;
        $messages = $pending['messages'] ?? null;
        $attachments = $pending['attachments'] ?? [];

        if (!is_array($customer) || !is_array($messages) || $event === '') {
            return;
        }

        $combinedContent = collect($messages)
            ->map(fn ($m) => is_array($m) ? trim((string) ($m['content'] ?? '')) : '')
            ->filter(fn ($c) => $c !== '')
            ->implode("\n\n");

        $first = is_array($messages[0] ?? null) ? $messages[0] : [];
        $messageType = (string) ($first['type'] ?? 'text');
        if ($combinedContent === '' && is_array($attachments) && count($attachments) > 0) {
            // Media-only batch.
            $messageType = (string) ($first['type'] ?? 'document');
        }

        $payload = [
            'event' => $event,
            'customer' => [
                'phone_number' => $customer['phone_number'] ?? null,
                'name' => $customer['name'] ?? null,
            ],
            'message' => [
                'id' => $first['id'] ?? null,
                'type' => $messageType,
                'content' => $combinedContent,
                'timestamp' => $first['timestamp'] ?? null,
            ],
            'attachments' => is_array($attachments) ? array_values($attachments) : [],
        ];

        if (isset($pending['ticket']) && is_array($pending['ticket'])) {
            $payload['ticket'] = $pending['ticket'];
        }

        // Mark as read (+ typing_indicator jika didukung Meta) untuk pesan terakhir (best-effort).
        $waMessageId = (string) ($first['id'] ?? '');
        if ($waMessageId !== '') {
            try {
                $notificationService->markAsRead($waMessageId, $messageType);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        try {
            Http::timeout(15)->withToken($secret)->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('n8n.webhook.failed', ['error' => $e->getMessage()]);
        }
    }
}
