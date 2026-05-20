<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        ->assertJsonPath('data.0.sender_type', 'customer');
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

