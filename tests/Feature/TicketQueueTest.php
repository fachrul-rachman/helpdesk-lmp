<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function tqDivision(): Division
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

function tqPic(Division $division): User
{
    return User::factory()->create([
        'role' => 'pic',
        'division_id' => $division->id,
        'password' => Hash::make('secret123'),
        'is_active' => true,
    ]);
}

function tqAuth(User $user): array
{
    return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user)];
}

test('queue logic: saat active ticket solved, ticket queue berikutnya otomatis aktif', function () {
    $division = tqDivision();
    $pic = tqPic($division);
    $customer = Customer::create(['phone_number' => '6289990000000', 'name' => 'Andi']);

    $active = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'medium',
        'status' => 'open',
        'subject' => 'Aktif',
        'notes' => null,
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    $queueLow = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'low',
        'status' => 'queue',
        'subject' => 'Queue Low',
        'notes' => null,
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ]);

    usleep(1000);

    $queueHigh = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'queue',
        'subject' => 'Queue High',
        'notes' => null,
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ]);

    $this->patchJson("/api/tickets/{$active->id}/status", [
        'status' => 'solved',
    ], tqAuth($pic))->assertOk();

    expect($queueHigh->fresh()->status)->toBe('new');
    expect($queueHigh->fresh()->activated_at)->not()->toBeNull();
    expect($queueHigh->fresh()->sla_fr_started_at)->not()->toBeNull();

    expect($queueLow->fresh()->status)->toBe('queue');
});

