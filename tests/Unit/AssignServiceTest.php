<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AssignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function asDivision(array $overrides = []): Division
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

function asPic(Division $division, array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'pic',
        'division_id' => $division->id,
        'password' => Hash::make('secret123'),
        'is_active' => true,
    ], $overrides));
}

test('autoAssign memilih PIC dengan beban terendah', function () {
    $division = asDivision();
    $picLow = asPic($division);
    $picHigh = asPic($division);

    $customer = Customer::create(['phone_number' => '6281110000000', 'name' => 'Andi']);

    // Beban tinggi: 2 ticket aktif
    Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $picHigh->id,
        'created_by' => 'ai',
        'priority' => 'medium',
        'status' => 'open',
        'subject' => 'A',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);
    Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $picHigh->id,
        'created_by' => 'ai',
        'priority' => 'medium',
        'status' => 'pending',
        'subject' => 'B',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'paused',
        'sla_resolution_paused_at' => now(),
    ]);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'new',
        'subject' => 'C',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ]);

    $service = app(AssignService::class);
    $picked = $service->autoAssign($ticket);

    expect($picked)->not->toBeNull();
    expect($picked?->id)->toBe($picLow->id);
    expect($ticket->fresh()->assigned_to)->toBe($picLow->id);
});

test('autoAssign memilih PIC dari relasi multi divisi', function () {
    $division = asDivision();
    $pic = User::factory()->create([
        'role' => 'pic',
        'division_id' => null,
        'password' => Hash::make('secret123'),
        'is_active' => true,
    ]);
    $pic->divisions()->attach($division);

    $customer = Customer::create(['phone_number' => '6281110000001', 'name' => 'Andi']);
    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'new',
        'subject' => 'Multi divisi',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ]);

    expect(app(AssignService::class)->autoAssign($ticket)?->id)->toBe($pic->id);
});

test('autoAssign redirect ke fallback jika divisi inactive', function () {
    $division = asDivision(['is_active' => false]);
    $fallback = asDivision(['is_fallback' => true, 'is_active' => true, 'name' => 'Fallback']);
    $spv = User::factory()->create([
        'role' => 'spv',
        'division_id' => null,
        'password' => Hash::make('secret123'),
        'is_active' => true,
    ]);

    $customer = Customer::create(['phone_number' => '6282220000000', 'name' => 'Andi']);
    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'low',
        'status' => 'new',
        'subject' => 'X',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ]);

    $service = app(AssignService::class);
    $picked = $service->autoAssign($ticket);

    expect($picked)->toBeNull();
    expect($ticket->fresh()->division_id)->toBe($fallback->id);
    expect($ticket->fresh()->assigned_to)->toBe($spv->id);
});
