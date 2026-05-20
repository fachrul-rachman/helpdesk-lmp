<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry 1x setelah 30 detik jika gagal (sesuai spesifikasi).
     */
    public int $tries = 2;
    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $logContext
     */
    public function __construct(
        private readonly array $payload,
        private readonly array $logContext = [],
        private readonly bool $enableRetry = true,
    ) {
        if (!$this->enableRetry) {
            $this->tries = 1;
            $this->backoff = 0;
        }
    }

    public function handle(): void
    {
        $token = (string) (getenv('META_WA_TOKEN') ?: env('META_WA_TOKEN', ''));
        $phoneNumberId = (string) (getenv('META_WA_PHONE_NUMBER_ID') ?: env('META_WA_PHONE_NUMBER_ID', ''));
        $apiUrl = rtrim((string) (getenv('META_WA_API_URL') ?: env('META_WA_API_URL', 'https://graph.facebook.com/v18.0')), '/');

        if ($token === '' || $phoneNumberId === '' || $apiUrl === '') {
            Log::warning('meta.missing_config', $this->logContext);
            return;
        }

        $url = "{$apiUrl}/{$phoneNumberId}/messages";

        try {
            $response = Http::timeout(15)
                ->withToken($token)
                ->post($url, $this->payload);

            if ($response->successful()) {
                Log::info('meta.send.success', $this->logContext + [
                    'status' => $response->status(),
                ]);
                return;
            }

            $status = $response->status();
            $body = $response->json();

            // 4xx (selain 429) biasanya request invalid -> jangan retry.
            if ($status >= 400 && $status < 500 && $status !== 429) {
                Log::error('meta.send.failed_no_retry', $this->logContext + [
                    'status' => $status,
                    'body' => $body,
                    // Sertakan payload untuk debug (tanpa token; token ada di header, bukan payload).
                    'payload' => $this->payload,
                ]);
                return;
            }

            Log::warning('meta.send.failed_retry', $this->logContext + [
                'status' => $status,
                'body' => $body,
            ]);

            if (!$this->enableRetry) {
                return;
            }

            // Trigger retry (1x) via mekanisme queue.
            throw new \RuntimeException("Meta API gagal ({$status}).");
        } catch (\Throwable $e) {
            Log::warning('meta.send.exception', $this->logContext + [
                'error' => $e->getMessage(),
                'attempt' => (int) $this->attempts(),
            ]);

            if (!$this->enableRetry) {
                return;
            }

            // Biarkan queue melakukan retry sesuai $tries/$backoff.
            throw $e;
        }
    }
}
