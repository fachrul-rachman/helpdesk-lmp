<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function makeDivision(array $overrides = []): Division
{
    return Division::create(array_merge([
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
    ], $overrides));
}

function makeUser(string $role, array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => $role,
        'password' => Hash::make('secret123'),
        'is_active' => true,
        'division_id' => null,
    ], $overrides));
}

function authHeaderFor(User $user): array
{
    return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user)];
}

test('new -> open otomatis saat human pertama reply', function () {
    $division = makeDivision();
    $pic = makeUser('pic', ['division_id' => $division->id]);
    $customer = Customer::create(['phone_number' => '6281234567890', 'name' => 'Andi']);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'new',
        'subject' => 'Laptop tidak menyala',
        'notes' => null,
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ]);

    $response = $this->postJson("/api/tickets/{$ticket->id}/messages", [
        'content' => 'Baik, saya bantu cek ya.',
    ], authHeaderFor($pic));

    $response->assertStatus(201)->assertJsonPath('data.sender_type', 'pic');
    expect($ticket->fresh()->status)->toBe('open');
});

test('validasi transisi status sesuai tabel', function () {
    $division = makeDivision();
    $pic = makeUser('pic', ['division_id' => $division->id]);
    $customer = Customer::create(['phone_number' => '6281234567000', 'name' => 'Andi']);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'medium',
        'status' => 'open',
        'subject' => 'Masalah jaringan',
        'notes' => null,
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    $this->patchJson("/api/tickets/{$ticket->id}/status", ['status' => 'pending'], authHeaderFor($pic))
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    $this->patchJson("/api/tickets/{$ticket->id}/status", ['status' => 'solved'], authHeaderFor($pic))
        ->assertStatus(422);

    $this->patchJson("/api/tickets/{$ticket->id}/status", ['status' => 'open'], authHeaderFor($pic))
        ->assertOk()
        ->assertJsonPath('data.status', 'open');

    $this->patchJson("/api/tickets/{$ticket->id}/status", ['status' => 'solved'], authHeaderFor($pic))
        ->assertOk()
        ->assertJsonPath('data.status', 'solved');
});

test('spv tidak bisa reply / ubah status ticket yang sudah di-assign ke pic', function () {
    $division = makeDivision();
    $pic = makeUser('pic', ['division_id' => $division->id]);
    $spv = makeUser('spv', ['division_id' => null]);
    $customer = Customer::create(['phone_number' => '6281234500000', 'name' => 'Andi']);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'low',
        'status' => 'open',
        'subject' => 'Keluhan',
        'notes' => null,
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    $this->postJson("/api/tickets/{$ticket->id}/messages", [
        'content' => 'Saya ambil alih ya.',
    ], authHeaderFor($spv))->assertStatus(403);

    $this->patchJson("/api/tickets/{$ticket->id}/status", [
        'status' => 'pending',
    ], authHeaderFor($spv))->assertStatus(403);
});

test('spv bisa reply & ubah status ticket fallback yang assigned ke spv', function () {
    $fallback = makeDivision(['is_fallback' => true, 'is_active' => true, 'name' => 'Fallback']);
    $spv = makeUser('spv', ['division_id' => null]);
    $customer = Customer::create(['phone_number' => '6281111111111', 'name' => 'Andi']);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $fallback->id,
        'assigned_to' => $spv->id,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'open',
        'subject' => 'Tidak terklasifikasi',
        'notes' => null,
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    $this->postJson("/api/tickets/{$ticket->id}/messages", [
        'content' => 'Baik, saya bantu.',
    ], authHeaderFor($spv))->assertStatus(201);

    $this->patchJson("/api/tickets/{$ticket->id}/status", [
        'status' => 'pending',
    ], authHeaderFor($spv))->assertOk();
});

