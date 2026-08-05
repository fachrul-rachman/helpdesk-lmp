<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppIncomingMessageJob;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $verifyToken = (string) ($request->query('hub.verify_token')
            ?? $request->query('hub_verify_token')
            ?? $request->query('hub_verifyToken')
            ?? '');

        $challenge = (string) ($request->query('hub.challenge')
            ?? $request->query('hub_challenge')
            ?? '');

        if ($verifyToken !== (string) config('services.meta_whatsapp.verify_token', '')) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200);
    }

    public function handle(Request $request)
    {
        $payload = $request->getContent();

        if (! $this->isValidSignature($payload, (string) $request->header('X-Hub-Signature-256'))) {
            return response('Unauthorized.', 401);
        }

        $data = $request->json()->all();
        $this->logStatuses($data);

        $messages = $this->extractMessages($data);

        foreach ($messages as $messagePayload) {
            ProcessWhatsAppIncomingMessageJob::dispatch($messagePayload);
        }

        return response()->json(['success' => true], 200);
    }

    private function isValidSignature(string $payload, ?string $headerSignature): bool
    {
        $secret = (string) config('services.meta_whatsapp.app_secret', '');
        if ($secret === '' || ! $headerSignature) {
            return false;
        }

        $computed = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($computed, $headerSignature);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function logStatuses(array $data): void
    {
        try {
            $entries = $data['entry'] ?? [];
            if (! is_array($entries)) {
                return;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $changes = $entry['changes'] ?? [];
                if (! is_array($changes)) {
                    continue;
                }

                foreach ($changes as $change) {
                    if (! is_array($change)) {
                        continue;
                    }

                    $value = $change['value'] ?? null;
                    if (! is_array($value)) {
                        continue;
                    }

                    $statuses = $value['statuses'] ?? [];
                    if (! is_array($statuses)) {
                        continue;
                    }

                    foreach ($statuses as $statusPayload) {
                        if (! is_array($statusPayload)) {
                            continue;
                        }

                        $status = (string) ($statusPayload['status'] ?? '');
                        $level = $status === 'failed' ? 'warning' : 'info';

                        Log::log($level, 'whatsapp.status', [
                            'id' => $statusPayload['id'] ?? null,
                            'recipient_id' => $statusPayload['recipient_id'] ?? null,
                            'status' => $status !== '' ? $status : null,
                            'timestamp' => $statusPayload['timestamp'] ?? null,
                            'errors' => $statusPayload['errors'] ?? null,
                            'conversation' => $statusPayload['conversation'] ?? null,
                            'pricing' => $statusPayload['pricing'] ?? null,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('whatsapp.status.parse_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function extractMessages(array $data): array
    {
        $result = [];

        try {
            $entry = $data['entry'][0] ?? null;
            $changes = $entry['changes'][0] ?? null;
            $value = $changes['value'] ?? null;

            if (! is_array($value)) {
                return [];
            }

            $contacts = $value['contacts'][0] ?? null;
            $customerName = is_array($contacts) ? (string) (($contacts['profile']['name'] ?? '') ?: '') : '';

            $messages = $value['messages'] ?? [];
            if (! is_array($messages)) {
                return [];
            }

            foreach ($messages as $m) {
                if (! is_array($m)) {
                    continue;
                }

                $from = PhoneNumber::normalize((string) ($m['from'] ?? ''));
                if ($from === '') {
                    continue;
                }

                $type = (string) ($m['type'] ?? 'text');
                $waMessageId = (string) ($m['id'] ?? '');
                $timestamp = (string) ($m['timestamp'] ?? '');

                $createdAt = null;
                if ($timestamp !== '' && ctype_digit($timestamp)) {
                    $createdAt = CarbonImmutable::createFromTimestampUTC((int) $timestamp);
                }

                $content = null;
                $attachments = [];

                if ($type === 'text') {
                    $content = (string) (($m['text']['body'] ?? '') ?: '');
                } elseif ($type === 'image') {
                    $content = (string) (($m['image']['caption'] ?? '') ?: '');
                    $attachments[] = [
                        'type' => 'image',
                        'media_id' => (string) ($m['image']['id'] ?? ''),
                        'mime_type' => (string) ($m['image']['mime_type'] ?? ''),
                        'file_name' => '',
                    ];
                } elseif ($type === 'video') {
                    $content = (string) (($m['video']['caption'] ?? '') ?: '');
                    $attachments[] = [
                        'type' => 'video',
                        'media_id' => (string) ($m['video']['id'] ?? ''),
                        'mime_type' => (string) ($m['video']['mime_type'] ?? ''),
                        'file_name' => '',
                    ];
                } elseif ($type === 'document') {
                    $content = (string) (($m['document']['caption'] ?? '') ?: '');
                    $attachments[] = [
                        'type' => 'document',
                        'media_id' => (string) ($m['document']['id'] ?? ''),
                        'mime_type' => (string) ($m['document']['mime_type'] ?? ''),
                        'file_name' => (string) ($m['document']['filename'] ?? ''),
                    ];
                } else {
                    continue;
                }

                $attachments = array_values(array_filter($attachments, fn ($a) => is_array($a) && (string) ($a['media_id'] ?? '') !== ''));

                $result[] = [
                    'customer_phone_number' => $from,
                    'customer_name' => $customerName,
                    'wa_message_id' => $waMessageId,
                    'timestamp' => $createdAt?->toISOString(),
                    'type' => $type,
                    'content' => $content,
                    'attachments' => $attachments,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('whatsapp.webhook.parse_failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }
}
