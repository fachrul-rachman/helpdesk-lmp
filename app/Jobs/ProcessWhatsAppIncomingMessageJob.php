<?php

namespace App\Jobs;

use App\Events\AiConversationUpdated;
use App\Events\MessageSent;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Ticket;
use App\Services\MediaService;
use App\Services\NotificationService;
use App\Services\SlaService;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use App\Events\TicketStatusChanged;
use App\Jobs\DebouncedForwardToN8nJob;

class ProcessWhatsAppIncomingMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $customerPhone = PhoneNumber::normalize((string) ($this->payload['customer_phone_number'] ?? ''));
        $customerName = (string) ($this->payload['customer_name'] ?? '');
        $waMessageId = (string) ($this->payload['wa_message_id'] ?? '');
        $messageType = (string) ($this->payload['type'] ?? 'text');
        $content = $this->payload['content'] ?? null;
        $timestamp = $this->payload['timestamp'] ?? null;
        $attachments = $this->payload['attachments'] ?? [];

        if ($customerPhone === '') {
            return;
        }

        /** @var Customer $customer */
        $customer = Customer::query()->firstOrCreate(
            ['phone_number' => $customerPhone],
            ['name' => $customerName !== '' ? $customerName : null],
        );

        if ($customer->name === null && $customerName !== '') {
            $customer->update(['name' => $customerName]);
        }

        $wasFirstInteraction = $customer->last_interaction_at === null;
        $customer->update(['last_interaction_at' => now()]);

        if ($wasFirstInteraction && $customer->phone_number) {
            $already = DB::table('audit_logs')
                ->where('action', 'customer.ai_introduction_sent')
                ->where('subject_type', 'Customer')
                ->where('subject_id', $customer->id)
                ->exists();

            if (!$already) {
                try {
                    app(NotificationService::class)->sendAiIntroduction($customer->phone_number, (string) ($customer->name ?? ''));
                    DB::table('audit_logs')->insert([
                        'user_id' => null,
                        'action' => 'customer.ai_introduction_sent',
                        'subject_type' => 'Customer',
                        'subject_id' => $customer->id,
                        'payload' => null,
                        'ip_address' => null,
                        'created_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('ai_introduction.first_chat_failed', ['customer_id' => $customer->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $activeTicket = Ticket::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
            ->orderByDesc('created_at')
            ->first();

        $ticketId = null;
        $shouldForwardToN8n = false;
        $n8nEvent = null;

        if ($activeTicket) {
            if ($activeTicket->status === 'on_progress') {
                $shouldForwardToN8n = true;
                $n8nEvent = 'message.on_progress';
            } elseif ($activeTicket->status === 'pending') {
                // Customer reply saat pending -> reopen ke open + resume SLA
                $fromStatus = (string) $activeTicket->status;
                $activeTicket->status = 'open';
                $activeTicket->save();

                try {
                    app(SlaService::class)->resumeSla($activeTicket->id);
                    $activeTicket->refresh();
                } catch (\Throwable $e) {
                    Log::warning('sla.resume_failed', ['ticket_id' => $activeTicket->id, 'error' => $e->getMessage()]);
                }

                event(new TicketStatusChanged([
                    'ticket_id' => $activeTicket->id,
                    'from_status' => $fromStatus,
                    'to_status' => 'open',
                    'changed_by' => ['id' => null, 'name' => 'Sistem', 'role' => 'system'],
                    'changed_at' => CarbonImmutable::now()->toISOString(),
                    'pic_id' => (string) ($activeTicket->assigned_to ?? ''),
                ]));

                $ticketId = $activeTicket->id;
            } else {
                $ticketId = $activeTicket->id;
            }
        } else {
            $shouldForwardToN8n = true;
            $n8nEvent = 'message.incoming';
        }

        $createdAt = null;
        if (is_string($timestamp) && $timestamp !== '') {
            try {
                $createdAt = CarbonImmutable::parse($timestamp);
            } catch (\Throwable) {
                $createdAt = null;
            }
        }

        $message = Message::create([
            'ticket_id' => $ticketId,
            'customer_id' => $customer->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'content' => is_string($content) ? $content : null,
            'wa_message_id' => $waMessageId !== '' ? $waMessageId : null,
            'created_at' => $createdAt ?? now(),
        ]);

        $attachmentPayloadForN8n = [];

        if (is_array($attachments) && count($attachments) > 0) {
            $mediaService = app(MediaService::class);

            foreach ($attachments as $att) {
                if (!is_array($att)) {
                    continue;
                }

                $mediaId = (string) ($att['media_id'] ?? '');
                $mimeType = (string) ($att['mime_type'] ?? '');
                $fileName = (string) ($att['file_name'] ?? '');
                $attType = (string) ($att['type'] ?? '');

                if ($mediaId === '') {
                    continue;
                }

                try {
                    $downloaded = $mediaService->downloadFromMeta($mediaId);
                    $r2Key = $mediaService->uploadToR2($downloaded['path'], 'media', $downloaded['ext'] ?? null);
                    $publicUrl = $mediaService->getPublicUrl($r2Key);

                    MessageAttachment::create([
                        'message_id' => $message->id,
                        'type' => $attType !== '' ? $attType : $messageType,
                        'file_name' => $fileName !== '' ? $fileName : (Str::uuid()->toString() . '.' . ($downloaded['ext'] ?? 'bin')),
                        'r2_key' => $r2Key,
                        'mime_type' => $mimeType !== '' ? $mimeType : ($downloaded['mime_type'] ?? 'application/octet-stream'),
                        'size_bytes' => (int) ($downloaded['size_bytes'] ?? 0),
                    ]);

                    $attachmentPayloadForN8n[] = [
                        'type' => $attType !== '' ? $attType : $messageType,
                        'r2_bucket' => (string) (getenv('CLOUDFLARE_R2_BUCKET') ?: env('CLOUDFLARE_R2_BUCKET', '')),
                        'r2_key' => $r2Key,
                        'r2_url' => $publicUrl,
                        'mime_type' => $mimeType !== '' ? $mimeType : ($downloaded['mime_type'] ?? ''),
                    ];
                } catch (\Throwable $e) {
                    Log::warning('whatsapp.media.failed', ['error' => $e->getMessage()]);
                }
            }
        }

        if ($shouldForwardToN8n && $n8nEvent) {
            $this->debouncedForwardToN8n(
                event: $n8nEvent,
                customer: $customer,
                ticket: $activeTicket,
                message: $message,
                messageType: $messageType,
                attachments: $attachmentPayloadForN8n,
            );

            event(new AiConversationUpdated([
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone_number' => $customer->phone_number,
                ],
                'message' => [
                    'sender_type' => 'customer',
                    'content' => $message->content,
                    'created_at' => optional($message->created_at)->toISOString(),
                ],
            ]));

            return;
        }

        if ($ticketId) {
            event(new MessageSent([
                'id' => $message->id,
                'ticket_id' => $ticketId,
                'sender_type' => 'customer',
                'sender' => ['name' => $customer->name],
                'content' => $message->content,
                'attachments' => MessageAttachment::query()
                    ->where('message_id', $message->id)
                    ->get()
                    ->map(fn (MessageAttachment $a) => [
                        'id' => $a->id,
                        'type' => $a->type,
                        'file_name' => $a->file_name,
                        'url' => app(MediaService::class)->getPublicUrl($a->r2_key),
                    ])
                    ->values()
                    ->all(),
                'created_at' => optional($message->created_at)->toISOString(),
            ]));
        }
    }

    private function debouncedForwardToN8n(
        string $event,
        Customer $customer,
        ?Ticket $ticket,
        Message $message,
        string $messageType,
        array $attachments,
    ): void {
        $debounceSeconds = (int) (getenv('N8N_DEBOUNCE_SECONDS') ?: env('N8N_DEBOUNCE_SECONDS', 5));
        $debounceSeconds = max(1, min(60, $debounceSeconds));

        $ticketPart = $event === 'message.on_progress' && $ticket
            ? (string) $ticket->id
            : 'no-ticket';

        $cacheKey = "n8n_debounce:{$event}:{$customer->id}:{$ticketPart}";

        /** @var array<string, mixed>|null $pending */
        $pending = Cache::get($cacheKey);
        $now = CarbonImmutable::now();

        $messagePayload = [
            'id' => $message->wa_message_id,
            'type' => $messageType,
            'content' => $message->content,
            'timestamp' => optional($message->created_at)->toISOString(),
        ];

        if (!$pending) {
            $pending = [
                'event' => $event,
                'customer' => [
                    'phone_number' => $customer->phone_number,
                    'name' => $customer->name,
                ],
                'messages' => [$messagePayload],
                'attachments' => $attachments,
                'last_updated_at' => $now->toISOString(),
            ];

            if ($event === 'message.on_progress' && $ticket) {
                $pending['ticket'] = [
                    'id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'subject' => $ticket->subject,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'division' => optional($ticket->division)->name,
                    'notes' => $ticket->notes,
                    'created_at' => optional($ticket->created_at)->toISOString(),
                ];
            }

            Cache::put($cacheKey, $pending, now()->addMinutes(15));
            DebouncedForwardToN8nJob::dispatch($cacheKey)->delay(now()->addSeconds($debounceSeconds));
            return;
        }

        $pendingMessages = $pending['messages'] ?? [];
        if (!is_array($pendingMessages)) {
            $pendingMessages = [];
        }
        $pendingMessages[] = $messagePayload;
        $pending['messages'] = $pendingMessages;

        $pendingAttachments = $pending['attachments'] ?? [];
        if (!is_array($pendingAttachments)) {
            $pendingAttachments = [];
        }
        foreach ($attachments as $att) {
            $pendingAttachments[] = $att;
        }
        $pending['attachments'] = $pendingAttachments;

        $pending['last_updated_at'] = $now->toISOString();
        Cache::put($cacheKey, $pending, now()->addMinutes(15));
    }
}
