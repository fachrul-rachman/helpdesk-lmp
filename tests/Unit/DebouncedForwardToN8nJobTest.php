<?php

use App\Jobs\DebouncedForwardToN8nJob;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('mark as read memakai message id terakhir dalam batch debounce', function () {
    config([
        'cache.default' => 'array',
        'services.n8n.webhook_url' => 'https://n8n.example.com/webhook',
        'services.n8n.secret' => 'n8n-secret',
    ]);

    Http::fake([
        'https://n8n.example.com/*' => Http::response(['ok' => true], 200),
    ]);

    Cache::put('debounce:test', [
        'event' => 'message.incoming',
        'customer' => [
            'phone_number' => '628123456789',
            'name' => 'Andi',
        ],
        'messages' => [
            [
                'id' => 'wamid.first',
                'type' => 'text',
                'content' => 'Halo',
                'timestamp' => now()->subSeconds(10)->toISOString(),
            ],
            [
                'id' => 'wamid.last',
                'type' => 'text',
                'content' => 'Ada yang mau ditanyakan',
                'timestamp' => now()->subSeconds(9)->toISOString(),
            ],
        ],
        'attachments' => [],
        'last_updated_at' => now()->subSeconds(8)->toISOString(),
    ], now()->addMinute());

    $notificationService = Mockery::mock(NotificationService::class);
    $notificationService->shouldReceive('markAsRead')
        ->once()
        ->with('wamid.last', 'text');

    (new DebouncedForwardToN8nJob('debounce:test'))->handle($notificationService);
});
