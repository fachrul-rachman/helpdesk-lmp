<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function tmDivision(): Division
{
    return Division::create([
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
}

function tmUser(string $role, ?string $divisionId = null): User
{
    return User::factory()->create([
        'role' => $role,
        'division_id' => $divisionId,
        'password' => Hash::make('secret123'),
        'is_active' => true,
    ]);
}

function tmAuth(User $user): array
{
    return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user)];
}

test('GET messages mengembalikan list pesan terurut dan POST messages membuat pesan', function () {
    $division = tmDivision();
    $pic = tmUser('pic', $division->id);
    $customer = Customer::create(['phone_number' => '6287770000000', 'name' => 'Andi']);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'open',
        'subject' => 'Laptop tidak menyala',
        'notes' => null,
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    Message::create([
        'ticket_id' => $ticket->id,
        'customer_id' => $customer->id,
        'sender_type' => 'customer',
        'sender_id' => null,
        'content' => 'Halo',
        'wa_message_id' => 'wa-1',
        'created_at' => now()->subMinute(),
    ]);

    $this->postJson("/api/tickets/{$ticket->id}/messages", [
        'content' => 'Baik, saya cek.',
    ], tmAuth($pic))->assertStatus(201)->assertJsonPath('data.sender_type', 'pic');

    $this->getJson("/api/tickets/{$ticket->id}/messages", tmAuth($pic))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.sender_type', 'customer')
        ->assertJsonPath('reply_window.is_open', true);
});

test('balasan langsung ditolak jika customer tidak mengirim pesan dalam 24 jam terakhir', function () {
    CarbonImmutable::setTestNow('2026-09-28 03:30:00');

    $division = tmDivision();
    $pic = tmUser('pic', $division->id);
    $customer = Customer::create(['phone_number' => '6287770000001', 'name' => 'Henni']);
    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'open',
        'subject' => 'Keluhan pelayanan',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    Message::create([
        'ticket_id' => $ticket->id,
        'customer_id' => $customer->id,
        'sender_type' => 'customer',
        'content' => 'Mohon dibantu',
        'wa_message_id' => 'wa-expired',
        'created_at' => now()->subHours(24)->subSecond(),
    ]);

    $this->getJson("/api/tickets/{$ticket->id}/messages", tmAuth($pic))
        ->assertOk()
        ->assertJsonPath('reply_window.is_open', false);

    $this->postJson("/api/tickets/{$ticket->id}/messages", [
        'content' => 'Kami bantu cek.',
    ], tmAuth($pic))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Balasan langsung belum tersedia. Hubungi customer terlebih dahulu.');

    $this->assertDatabaseMissing('messages', [
        'ticket_id' => $ticket->id,
        'content' => 'Kami bantu cek.',
    ]);
});

test('PIC dapat mengirim template untuk meminta customer melanjutkan percakapan', function () {
    CarbonImmutable::setTestNow('2026-09-28 03:30:00');
    config([
        'queue.default' => 'sync',
        'services.meta_whatsapp.token' => 'test-token',
        'services.meta_whatsapp.phone_number_id' => '123',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
    ]);
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.resume']]], 200),
    ]);

    $division = tmDivision();
    $pic = tmUser('pic', $division->id);
    $customer = Customer::create(['phone_number' => '6287770000002', 'name' => 'Henni']);
    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'open',
        'subject' => 'Keluhan pelayanan',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    Message::create([
        'ticket_id' => $ticket->id,
        'customer_id' => $customer->id,
        'sender_type' => 'customer',
        'content' => 'Mohon dibantu',
        'wa_message_id' => 'wa-expired',
        'created_at' => now()->subDays(2),
    ]);

    $this->postJson("/api/tickets/{$ticket->id}/resume-conversation", [
        'customer_name' => 'Ibu Henni',
        'summary' => 'keluhan pelayanan yang sedang ditindaklanjuti',
    ], tmAuth($pic))->assertAccepted();

    Http::assertSent(fn ($request) => ($request['to'] ?? null) === '6287770000002'
        && ($request['template']['name'] ?? null) === 'resume_customer_conversation'
        && ($request['template']['language']['code'] ?? null) === 'id'
        && ($request['template']['components'][0]['parameters'][0]['text'] ?? null) === 'Ibu Henni'
        && ($request['template']['components'][0]['parameters'][1]['text'] ?? null) === 'keluhan pelayanan yang sedang ditindaklanjuti');
});

test('PIC tidak bisa reply ticket bukan miliknya', function () {
    $division = tmDivision();
    $picA = tmUser('pic', $division->id);
    $picB = tmUser('pic', $division->id);
    $customer = Customer::create(['phone_number' => '6287000000000', 'name' => 'Andi']);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $picA->id,
        'created_by' => 'ai',
        'priority' => 'medium',
        'status' => 'open',
        'subject' => 'X',
        'notes' => null,
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    $this->postJson("/api/tickets/{$ticket->id}/messages", [
        'content' => 'Tes',
    ], tmAuth($picB))->assertStatus(404);
});

test('ticket manual menampilkan riwayat chat customer dari ticket sebelumnya', function () {
    $division = tmDivision();
    $spv = tmUser('spv');
    $customer = Customer::create(['phone_number' => '6287000000001', 'name' => 'Andi']);

    $oldTicket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'medium',
        'status' => 'open',
        'subject' => 'Ticket lama',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);
    $oldMessage = Message::create([
        'ticket_id' => $oldTicket->id,
        'customer_id' => $customer->id,
        'sender_type' => 'customer',
        'content' => 'Percakapan sebelum ticket manual',
    ]);
    $oldMessage->forceFill(['created_at' => now()->subMonths(6)])->save();

    $manualTicket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'spv',
        'priority' => 'medium',
        'status' => 'queue',
        'subject' => 'Ticket manual',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ]);

    $this->getJson("/api/tickets/{$manualTicket->id}/messages", tmAuth($spv))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.content', 'Percakapan sebelum ticket manual');
});
