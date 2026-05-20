<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function metaEnvelope(array $message, string $customerName = 'Andi'): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => 'WABA_ID',
                'changes' => [
                    [
                        'field' => 'messages',
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => [
                                'display_phone_number' => '628000000000',
                                'phone_number_id' => 'PHONE_NUMBER_ID',
                            ],
                            'contacts' => [
                                [
                                    'profile' => ['name' => $customerName],
                                    'wa_id' => '628123456789',
                                ],
                            ],
                            'messages' => [$message],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function signPayload(string $payload, string $secret): string
{
    return 'sha256=' . hash_hmac('sha256', $payload, $secret);
}

beforeEach(function () {
    config(['queue.default' => 'sync']);
    putenv('META_WA_APP_SECRET=test-secret');
    $_ENV['META_WA_APP_SECRET'] = 'test-secret';
});

test('invalid meta signature rejected', function () {
    $payload = json_encode(metaEnvelope([
        'id' => 'wamid.1',
        'from' => '628123456789',
        'timestamp' => (string) time(),
        'type' => 'text',
        'text' => ['body' => 'Halo'],
    ]), JSON_UNESCAPED_SLASHES);

    $response = $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
    ], $payload);

    $response->assertStatus(401);
});

test('incoming message forwarded to n8n if no active ticket', function () {
    putenv('N8N_WEBHOOK_URL=https://n8n.example.com/webhook/abc');
    putenv('N8N_SECRET=n8n-secret');
    $_ENV['N8N_WEBHOOK_URL'] = 'https://n8n.example.com/webhook/abc';
    $_ENV['N8N_SECRET'] = 'n8n-secret';

    Http::fake([
        'https://n8n.example.com/*' => Http::response(['ok' => true], 200),
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    $payload = json_encode(metaEnvelope([
        'id' => 'wamid.2',
        'from' => '628123456789',
        'timestamp' => (string) time(),
        'type' => 'text',
        'text' => ['body' => 'Halo, saya butuh bantuan'],
    ]), JSON_UNESCAPED_SLASHES);

    $signature = signPayload($payload, 'test-secret');

    $response = $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $payload);

    $response->assertOk();

    expect(Customer::count())->toBe(1);
    expect(\App\Models\Message::count())->toBe(1);

    Http::assertSent(function ($request) {
        return str_starts_with((string) $request->url(), 'https://n8n.example.com/webhook/abc')
            && $request->hasHeader('Authorization', 'Bearer n8n-secret')
            && ($request['event'] ?? null) === 'message.incoming';
    });
});

test('incoming message not forwarded if active ticket exists', function () {
    putenv('N8N_WEBHOOK_URL=https://n8n.example.com/webhook/abc');
    putenv('N8N_SECRET=n8n-secret');
    $_ENV['N8N_WEBHOOK_URL'] = 'https://n8n.example.com/webhook/abc';
    $_ENV['N8N_SECRET'] = 'n8n-secret';

    Http::fake([
        'https://n8n.example.com/*' => Http::response(['ok' => true], 200),
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    $division = Division::create([
        'name' => 'Teknis',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false,
        'is_active' => true,
    ]);

    $customer = Customer::create(['phone_number' => '628123456789', 'name' => 'Andi', 'last_interaction_at' => now()]);
    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'spv',
        'priority' => 'medium',
        'status' => 'open',
        'subject' => 'Test',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'running',
    ]);

    $payload = json_encode(metaEnvelope([
        'id' => 'wamid.3',
        'from' => '628123456789',
        'timestamp' => (string) time(),
        'type' => 'text',
        'text' => ['body' => 'Update dong'],
    ]), JSON_UNESCAPED_SLASHES);
    $signature = signPayload($payload, 'test-secret');

    $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $payload)->assertOk();

    $message = \App\Models\Message::query()->firstOrFail();
    expect($message->ticket_id)->toBe($ticket->id);

    Http::assertNothingSent();
});

test('incoming message on on_progress forwarded to n8n with ticket context', function () {
    putenv('N8N_WEBHOOK_URL=https://n8n.example.com/webhook/abc');
    putenv('N8N_SECRET=n8n-secret');
    $_ENV['N8N_WEBHOOK_URL'] = 'https://n8n.example.com/webhook/abc';
    $_ENV['N8N_SECRET'] = 'n8n-secret';

    Http::fake([
        'https://n8n.example.com/*' => Http::response(['ok' => true], 200),
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    $division = Division::create([
        'name' => 'Teknis',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false,
        'is_active' => true,
    ]);

    $customer = Customer::create(['phone_number' => '628123456789', 'name' => 'Andi', 'last_interaction_at' => now()]);
    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'spv',
        'priority' => 'medium',
        'status' => 'on_progress',
        'subject' => 'Test',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'paused',
    ]);

    $payload = json_encode(metaEnvelope([
        'id' => 'wamid.4',
        'from' => '628123456789',
        'timestamp' => (string) time(),
        'type' => 'text',
        'text' => ['body' => 'Ada update?'],
    ]), JSON_UNESCAPED_SLASHES);
    $signature = signPayload($payload, 'test-secret');

    $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $payload)->assertOk();

    $message = \App\Models\Message::query()->firstOrFail();
    expect($message->ticket_id)->toBeNull();

    Http::assertSent(function ($request) use ($ticket) {
        return ($request['event'] ?? null) === 'message.on_progress'
            && (($request['ticket']['id'] ?? null) === $ticket->id);
    });
});

test('media downloaded from meta and uploaded to r2', function () {
    config(['queue.default' => 'sync']);
    Storage::fake('r2');

    putenv('META_WA_TOKEN=meta-token');
    putenv('META_WA_API_URL=https://graph.facebook.com/v18.0');
    $_ENV['META_WA_TOKEN'] = 'meta-token';
    $_ENV['META_WA_API_URL'] = 'https://graph.facebook.com/v18.0';

    Http::fake(function ($request) {
        $url = (string) $request->url();

        if ($url === 'https://graph.facebook.com/v18.0/MEDIA_ID') {
            return Http::response([
                'url' => 'https://download.example.com/file',
                'mime_type' => 'image/jpeg',
                'file_size' => 3,
                'id' => 'MEDIA_ID',
            ], 200);
        }

        if ($url === 'https://download.example.com/file') {
            return Http::response('abc', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response([], 404);
    });

    $division = Division::create([
        'name' => 'Teknis',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false,
        'is_active' => true,
    ]);

    $customer = Customer::create(['phone_number' => '628123456789', 'name' => 'Andi']);
    Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'spv',
        'priority' => 'medium',
        'status' => 'open',
        'subject' => 'Test',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'running',
    ]);

    $payload = json_encode(metaEnvelope([
        'id' => 'wamid.media',
        'from' => '628123456789',
        'timestamp' => (string) time(),
        'type' => 'image',
        'image' => [
            'id' => 'MEDIA_ID',
            'mime_type' => 'image/jpeg',
            'caption' => 'Ini fotonya',
        ],
    ]), JSON_UNESCAPED_SLASHES);
    $signature = signPayload($payload, 'test-secret');

    $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $payload)->assertOk();

    expect(\App\Models\MessageAttachment::count())->toBe(1);

    $attachment = \App\Models\MessageAttachment::query()->firstOrFail();
    Storage::disk('r2')->assertExists($attachment->r2_key);
});
