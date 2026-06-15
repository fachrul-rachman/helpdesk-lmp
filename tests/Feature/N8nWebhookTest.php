<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\MessageAttachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);

    putenv('N8N_INCOMING_SECRET=incoming-secret');
    $_ENV['N8N_INCOMING_SECRET'] = 'incoming-secret';
});

test('invalid n8n secret rejected', function () {
    $this->postJson('/api/webhook/n8n', ['event' => 'message.reply'], [
        'X-N8N-Secret' => 'wrong',
    ])->assertStatus(401);
});

test('ticket created from n8n payload', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    putenv('META_WA_TOKEN=meta-token');
    putenv('META_WA_PHONE_NUMBER_ID=PHONE_ID');
    putenv('META_WA_API_URL=https://graph.facebook.com/v18.0');
    $_ENV['META_WA_TOKEN'] = 'meta-token';
    $_ENV['META_WA_PHONE_NUMBER_ID'] = 'PHONE_ID';
    $_ENV['META_WA_API_URL'] = 'https://graph.facebook.com/v18.0';

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

    Division::create([
        'name' => 'Fallback',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => true,
        'is_active' => true,
    ]);

    $resp = $this->postJson('/api/webhook/n8n', [
        'event' => 'ticket.create',
        'customer_phone_number' => '08123456789',
        'ticket' => [
            'subject' => 'Laptop rusak',
            'priority' => 'high',
            'division_id' => $division->id,
            'ai_confidence' => 0.92,
            'is_fallback' => false,
        ],
        'ai_reply' => [
            'message' => 'Kami buat tiket ya.',
            'type' => 'text',
        ],
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertOk()->assertJson(['success' => true]);
    expect(Ticket::count())->toBe(1);
});

test('fallback ticket created correctly', function () {
    $fallback = Division::create([
        'name' => 'Fallback',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => true,
        'is_active' => true,
    ]);

    User::factory()->create(['role' => 'spv', 'division_id' => null]);

    $resp = $this->postJson('/api/webhook/n8n', [
        'event' => 'ticket.create',
        'customer_phone_number' => '08123456789',
        'ticket' => [
            'subject' => 'Tidak jelas',
            'priority' => 'medium',
            'division_id' => null,
            'ai_confidence' => 0.31,
            'is_fallback' => true,
        ],
        'ai_reply' => [
            'message' => 'Kami hubungkan ya.',
            'type' => 'text',
        ],
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertOk();
    $ticket = Ticket::query()->firstOrFail();
    expect($ticket->division_id)->toBe($fallback->id);
    expect($ticket->assigned_to)->not()->toBeNull();
});

test('ai reply sent to customer', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    putenv('META_WA_TOKEN=meta-token');
    putenv('META_WA_PHONE_NUMBER_ID=PHONE_ID');
    putenv('META_WA_API_URL=https://graph.facebook.com/v18.0');
    $_ENV['META_WA_TOKEN'] = 'meta-token';
    $_ENV['META_WA_PHONE_NUMBER_ID'] = 'PHONE_ID';
    $_ENV['META_WA_API_URL'] = 'https://graph.facebook.com/v18.0';

    $customer = Customer::create(['phone_number' => '628123456789', 'name' => 'Andi']);

    $resp = $this->postJson('/api/webhook/n8n', [
        'event' => 'message.reply',
        'customer_phone_number' => '08123456789',
        'ai_reply' => [
            'message' => 'Jam operasional...',
            'type' => 'text',
        ],
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertOk();

    Http::assertSentCount(1);
});

test('ai media reply sends supported media types to customer', function (string $mediaType, string $key, string $expectedUrl) {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    putenv('META_WA_TOKEN=meta-token');
    putenv('META_WA_PHONE_NUMBER_ID=PHONE_ID');
    putenv('META_WA_API_URL=https://graph.facebook.com/v18.0');
    putenv('CLOUDFLARE_R2_URL=https://cdn.example.test');
    $_ENV['META_WA_TOKEN'] = 'meta-token';
    $_ENV['META_WA_PHONE_NUMBER_ID'] = 'PHONE_ID';
    $_ENV['META_WA_API_URL'] = 'https://graph.facebook.com/v18.0';
    $_ENV['CLOUDFLARE_R2_URL'] = 'https://cdn.example.test';

    Customer::create(['phone_number' => '628123456789', 'name' => 'Andi']);

    $resp = $this->postJson('/api/webhook/n8n', [
        'event' => 'message.reply',
        'customer_phone_number' => '08123456789',
        'ai_reply' => [
            'type' => 'media',
            'media_type' => $mediaType,
            'key' => $key,
            'caption' => 'Berikut filenya.',
        ],
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertOk()->assertJson(['success' => true]);

    Http::assertSent(fn ($request) => ($request['type'] ?? null) === $mediaType
        && (($request[$mediaType]['link'] ?? null) === $expectedUrl)
        && (($request[$mediaType]['caption'] ?? null) === 'Berikut filenya.'));

    expect(MessageAttachment::query()->where('type', $mediaType)->where('r2_key', $key)->exists())->toBeTrue();
})->with([
    'image' => ['image', 'media/2026/05/panduan.jpg', 'https://cdn.example.test/media/2026/05/panduan.jpg'],
    'document' => ['document', 'media/2026/05/Update Zone B Tangerang 15 Mei 2026.pdf', 'https://cdn.example.test/media/2026/05/Update%20Zone%20B%20Tangerang%2015%20Mei%202026.pdf'],
    'video' => ['video', 'media/2026/05/panduan.mp4', 'https://cdn.example.test/media/2026/05/panduan.mp4'],
]);

test('ai media reply rejects unsafe storage key', function () {
    Customer::create(['phone_number' => '628123456789', 'name' => 'Andi']);

    $resp = $this->postJson('/api/webhook/n8n', [
        'event' => 'message.reply',
        'customer_phone_number' => '08123456789',
        'ai_reply' => [
            'type' => 'media',
            'media_type' => 'image',
            'key' => 'https://evil.example/file.jpg',
            'caption' => 'x',
        ],
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertStatus(422);
});

test('ai media reply rejects media type that does not match file extension', function () {
    Customer::create(['phone_number' => '628123456789', 'name' => 'Andi']);

    $resp = $this->postJson('/api/webhook/n8n', [
        'event' => 'message.reply',
        'customer_phone_number' => '08123456789',
        'ai_reply' => [
            'type' => 'media',
            'media_type' => 'image',
            'key' => 'media/2026/05/Update Zone B Tangerang 15 Mei 2026.pdf',
            'caption' => 'Berikut filenya.',
        ],
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertStatus(422);
});

test('reopen from on_progress', function () {
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

    $resp = $this->postJson('/api/webhook/n8n', [
        'event' => 'ticket.reopen_from_on_progress',
        'ticket_id' => $ticket->id,
        'ai_reply' => ['message' => 'OK', 'type' => 'text'],
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertOk();
    expect($ticket->fresh()->status)->toBe('open');
});

test('system error sends template', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    putenv('META_WA_TOKEN=meta-token');
    putenv('META_WA_PHONE_NUMBER_ID=PHONE_ID');
    putenv('META_WA_API_URL=https://graph.facebook.com/v18.0');
    $_ENV['META_WA_TOKEN'] = 'meta-token';
    $_ENV['META_WA_PHONE_NUMBER_ID'] = 'PHONE_ID';
    $_ENV['META_WA_API_URL'] = 'https://graph.facebook.com/v18.0';

    Customer::create(['phone_number' => '628123456789', 'name' => 'Andi']);

    $resp = $this->postJson('/api/webhook/n8n', [
        'event' => 'system.error',
        'customer_phone_number' => '08123456789',
        'error' => 'fail',
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertOk();

    Http::assertSent(fn ($request) => (($request['type'] ?? null) === 'template'));
});
