<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function taAdminHeader(): array
{
    $admin = User::factory()->create([
        'role' => 'admin',
        'division_id' => null,
        'password' => Hash::make('admin123456'),
        'is_active' => true,
    ]);

    return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($admin)];
}

test('reassign saat PIC dinonaktifkan', function () {
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

    $picA = User::factory()->create([
        'role' => 'pic',
        'division_id' => $division->id,
        'password' => Hash::make('secret123'),
        'is_active' => true,
    ]);
    $picB = User::factory()->create([
        'role' => 'pic',
        'division_id' => $division->id,
        'password' => Hash::make('secret123'),
        'is_active' => true,
    ]);

    $customer = Customer::create(['phone_number' => '6283330000000', 'name' => 'Andi']);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $picA->id,
        'created_by' => 'ai',
        'priority' => 'medium',
        'status' => 'open',
        'subject' => 'Test',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    $this->putJson("/api/admin/users/{$picA->id}", [
        'is_active' => false,
    ], taAdminHeader())->assertOk();

    $ticket->refresh();
    expect($ticket->assigned_to)->not->toBe($picA->id);
    expect($ticket->assigned_to)->toBeIn([$picB->id]);
});

