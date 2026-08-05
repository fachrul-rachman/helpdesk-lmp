<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function authHeader(User $user): array
{
    return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user)];
}

test('attachment url endpoint returns public url and metadata', function () {
    config([
        'filesystems.disks.r2.url' => 'https://cdn.example.com',
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

    $pic = User::factory()->create([
        'role' => 'pic',
        'division_id' => $division->id,
        'is_active' => true,
    ]);

    $customer = Customer::create(['phone_number' => '6281111111111', 'name' => 'Andi']);
    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'spv',
        'priority' => 'low',
        'status' => 'open',
        'subject' => 'Test',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    $message = Message::create([
        'ticket_id' => $ticket->id,
        'customer_id' => $customer->id,
        'sender_type' => 'pic',
        'sender_id' => $pic->id,
        'content' => 'Lampiran',
        'wa_message_id' => null,
        'created_at' => now(),
    ]);

    $att = MessageAttachment::create([
        'message_id' => $message->id,
        'type' => 'image',
        'file_name' => 'foto.jpg',
        'r2_key' => 'media/2026/01/uuid.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 123,
    ]);

    $this->getJson("/api/attachments/{$att->id}/url", authHeader($pic))
        ->assertOk()
        ->assertJson([
            'url' => 'https://cdn.example.com/media/2026/01/uuid.jpg',
            'type' => 'image',
            'file_name' => 'foto.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123,
        ]);
});
