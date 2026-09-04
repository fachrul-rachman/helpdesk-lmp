<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\MessageAttachment;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'queue.default' => 'sync',
        'broadcasting.default' => 'log',
    ]);
    config([
        'services.n8n.incoming_secret' => 'incoming-secret',
    ]);
});

function noisyPngBytes(int $size = 1500): string
{
    $image = imagecreatetruecolor($size, $size);
    if (! $image instanceof GdImage) {
        throw new RuntimeException('GD image creation failed.');
    }

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
        }
    }

    ob_start();
    imagepng($image, null, 0);
    $bytes = ob_get_clean();
    imagedestroy($image);

    if (! is_string($bytes)) {
        throw new RuntimeException('PNG encoding failed.');
    }

    return $bytes;
}

test('invalid n8n secret rejected', function () {
    $this->postJson('/api/webhook/n8n', ['event' => 'message.reply'], [
        'X-N8N-Secret' => 'wrong',
    ])->assertStatus(401);
});

test('ticket created from n8n payload', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    config([
        'services.meta_whatsapp.token' => 'meta-token',
        'services.meta_whatsapp.phone_number_id' => 'PHONE_ID',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
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

    $globalSubcategory = TicketSubcategory::create([
        'name' => 'Complaint',
        'division_id' => null,
        'is_active' => true,
    ]);
    $divisionSubcategory = TicketSubcategory::create([
        'name' => 'Tomb Pecah',
        'division_id' => $division->id,
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
            'global_subcategory_id' => $globalSubcategory->id,
            'division_subcategory_id' => $divisionSubcategory->id,
            'site' => 'LMP Karawang',
            'zone' => 'Zone B',
            'lot_number' => 'B-12-08',
        ],
        'ai_reply' => [
            'message' => 'Kami buat tiket ya.',
            'type' => 'text',
        ],
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertOk()->assertJson(['success' => true]);
    $ticket = Ticket::query()->firstOrFail();
    expect($ticket->global_subcategory_id)->toBe($globalSubcategory->id)
        ->and($ticket->division_subcategory_id)->toBe($divisionSubcategory->id)
        ->and($ticket->site)->toBe('LMP Karawang')
        ->and($ticket->zone)->toBe('Zone B')
        ->and($ticket->lot_number)->toBe('B-12-08');
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

    config([
        'services.meta_whatsapp.token' => 'meta-token',
        'services.meta_whatsapp.phone_number_id' => 'PHONE_ID',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
    ]);

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
    Storage::fake('r2');
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    config([
        'services.meta_whatsapp.token' => 'meta-token',
        'services.meta_whatsapp.phone_number_id' => 'PHONE_ID',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
        'filesystems.disks.r2.url' => 'https://cdn.example.test',
    ]);

    Storage::disk('r2')->put($key, 'file');
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

test('ai media reply can send image file as document fallback', function () {
    Storage::fake('r2');
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    config([
        'services.meta_whatsapp.token' => 'meta-token',
        'services.meta_whatsapp.phone_number_id' => 'PHONE_ID',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
        'filesystems.disks.r2.url' => 'https://cdn.example.test',
    ]);

    Storage::disk('r2')->put('media/2026/05/sitemap-karawang.png', 'file');
    Customer::create(['phone_number' => '628123456789', 'name' => 'Andi']);

    $resp = $this->postJson('/api/webhook/n8n', [
        'event' => 'message.reply',
        'customer_phone_number' => '08123456789',
        'ai_reply' => [
            'type' => 'media',
            'media_type' => 'document',
            'key' => 'media/2026/05/sitemap-karawang.png',
            'caption' => 'Berikut filenya.',
            'filename' => 'sitemap-karawang.png',
        ],
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertOk();

    Http::assertSent(fn ($request) => ($request['type'] ?? null) === 'document'
        && (($request['document']['filename'] ?? null) === 'sitemap-karawang.png'));
});

test('ai media reply compresses large image before sending as image', function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD extension is not available.');
    }

    Storage::fake('r2');
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    config([
        'services.meta_whatsapp.token' => 'meta-token',
        'services.meta_whatsapp.phone_number_id' => 'PHONE_ID',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
        'filesystems.disks.r2.url' => 'https://cdn.example.test',
    ]);

    $key = 'media/2026/05/large-sitemap.png';
    $bytes = noisyPngBytes();
    expect(strlen($bytes))->toBeGreaterThan(MediaService::WHATSAPP_IMAGE_MAX_BYTES);
    Storage::disk('r2')->put($key, $bytes);

    Customer::create(['phone_number' => '628123456789', 'name' => 'Andi']);

    $resp = $this->postJson('/api/webhook/n8n', [
        'event' => 'message.reply',
        'customer_phone_number' => '08123456789',
        'ai_reply' => [
            'type' => 'media',
            'media_type' => 'image',
            'key' => $key,
            'caption' => 'Berikut filenya.',
            'filename' => 'large-sitemap.png',
        ],
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertOk();

    Http::assertSent(fn ($request) => ($request['type'] ?? null) === 'image'
        && str_contains((string) ($request['image']['link'] ?? ''), '/compressed/')
        && str_ends_with((string) ($request['image']['link'] ?? ''), '.jpg')
        && (($request['image']['caption'] ?? null) === 'Berikut filenya.'));

    $attachment = MessageAttachment::query()->where('type', 'image')->firstOrFail();
    expect($attachment->r2_key)->toContain('/compressed/');
    expect($attachment->mime_type)->toBe('image/jpeg');
    Storage::disk('r2')->assertExists($attachment->r2_key);
    expect(Storage::disk('r2')->size($attachment->r2_key))->toBeLessThanOrEqual(MediaService::WHATSAPP_IMAGE_MAX_BYTES);
});

test('ai media reply falls back to document when large image cannot be compressed', function () {
    Storage::fake('r2');
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    config([
        'services.meta_whatsapp.token' => 'meta-token',
        'services.meta_whatsapp.phone_number_id' => 'PHONE_ID',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
        'filesystems.disks.r2.url' => 'https://cdn.example.test',
    ]);

    $key = 'media/2026/05/broken-sitemap.png';
    Storage::disk('r2')->put($key, str_repeat('x', MediaService::WHATSAPP_IMAGE_MAX_BYTES + 1));

    Customer::create(['phone_number' => '628123456789', 'name' => 'Andi']);

    $resp = $this->postJson('/api/webhook/n8n', [
        'event' => 'message.reply',
        'customer_phone_number' => '08123456789',
        'ai_reply' => [
            'type' => 'media',
            'media_type' => 'image',
            'key' => $key,
            'caption' => 'Berikut filenya.',
            'filename' => 'broken-sitemap.png',
        ],
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertOk();

    Http::assertSent(fn ($request) => ($request['type'] ?? null) === 'document'
        && (($request['document']['filename'] ?? null) === 'broken-sitemap.png')
        && (($request['document']['caption'] ?? null) === 'Berikut filenya.'));

    $attachment = MessageAttachment::query()->where('type', 'document')->firstOrFail();
    expect($attachment->r2_key)->toBe($key);
});

test('ai media reply rejects missing storage key before sending to meta', function () {
    Storage::fake('r2');
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    config([
        'services.meta_whatsapp.token' => 'meta-token',
        'services.meta_whatsapp.phone_number_id' => 'PHONE_ID',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
        'filesystems.disks.r2.url' => 'https://cdn.example.test',
    ]);

    Customer::create(['phone_number' => '628123456789', 'name' => 'Andi']);

    $resp = $this->postJson('/api/webhook/n8n', [
        'event' => 'message.reply',
        'customer_phone_number' => '08123456789',
        'ai_reply' => [
            'type' => 'media',
            'media_type' => 'image',
            'key' => 'media/2026/05/missing.jpg',
            'caption' => 'Berikut filenya.',
        ],
    ], [
        'X-N8N-Secret' => 'incoming-secret',
    ]);

    $resp->assertStatus(422);

    Http::assertNothingSent();
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

    config([
        'services.meta_whatsapp.token' => 'meta-token',
        'services.meta_whatsapp.phone_number_id' => 'PHONE_ID',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
    ]);

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

test('n8n webhook still uses cached config secret when env changes', function () {
    config([
        'services.n8n.incoming_secret' => 'cached-incoming-secret',
    ]);
    putenv('N8N_INCOMING_SECRET=wrong-secret');

    $this->postJson('/api/webhook/n8n', [
        'event' => 'message.reply',
        'customer_phone_number' => '08123456789',
        'ai_reply' => [
            'message' => 'tes',
            'type' => 'text',
        ],
    ], [
        'X-N8N-Secret' => 'cached-incoming-secret',
    ])->assertStatus(422);
});
