<?php

use App\Models\Division;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function adminHeader(): array
{
    $admin = User::factory()->create([
        'role' => 'admin',
        'phone_number' => '628' . fake()->unique()->numerify(str_repeat('#', 9)),
        'password' => Hash::make('admin123456'),
        'is_active' => true,
        'division_id' => null,
    ]);

    return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($admin)];
}

test('admin can create division', function () {
    $response = $this->postJson('/api/admin/divisions', [
        'name' => 'Teknis',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false
    ], adminHeader());

    $response->assertStatus(201)->assertJson(['message' => 'Divisi berhasil dibuat.']);
});

test('only one fallback division allowed', function () {
    $first = $this->postJson('/api/admin/divisions', [
        'name' => 'Fallback 1',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => true
    ], adminHeader());
    $first->assertStatus(201);

    $second = $this->postJson('/api/admin/divisions', [
        'name' => 'Fallback 2',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => true
    ], adminHeader());

    $second->assertStatus(422);
});

test('working hours default monday to friday', function () {
    $response = $this->postJson('/api/admin/divisions', [
        'name' => 'Billing',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false
    ], adminHeader());
    $response->assertStatus(201);

    /** @var Division $division */
    $division = Division::query()->where('name', 'Billing')->with('workingHours')->firstOrFail();

    $map = $division->workingHours->keyBy('day_of_week');
    expect($map['monday']->is_active)->toBeTrue();
    expect($map['friday']->is_active)->toBeTrue();
    expect($map['saturday']->is_active)->toBeFalse();
    expect($map['sunday']->is_active)->toBeFalse();
});

test('cannot delete division with active tickets', function () {
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

    $customer = \App\Models\Customer::create([
        'phone_number' => '628111111111',
        'name' => 'Andi',
    ]);

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

    $response = $this->deleteJson('/api/admin/divisions/' . $division->id, [], adminHeader());
    $response->assertStatus(422);
});
