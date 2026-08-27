<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function adminAuthHeader(): array
{
    $admin = User::factory()->create([
        'role' => 'admin',
        'phone_number' => '628'.fake()->unique()->numerify(str_repeat('#', 9)),
        'password' => Hash::make('admin123456'),
        'is_active' => true,
        'division_id' => null,
    ]);

    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($admin)];
}

test('admin can create user', function () {
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
        'is_active' => false,
    ]);

    $response = $this->postJson('/api/admin/users', [
        'name' => 'Budi Santoso',
        'phone_number' => '08123456789',
        'role' => 'pic',
        'division_id' => $division->id,
        'password' => 'tempPassword123',
    ], adminAuthHeader());

    $response->assertStatus(201)->assertJson(['message' => 'User berhasil dibuat.']);
    expect($division->fresh()->is_active)->toBeTrue();
});

test('admin can assign pic to multiple divisions', function () {
    $divisions = collect(['Teknis', 'Billing'])->map(fn (string $name) => Division::create([
        'name' => $name,
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false,
        'is_active' => false,
    ]));

    $response = $this->postJson('/api/admin/users', [
        'name' => 'Multi Divisi',
        'phone_number' => '08123456788',
        'role' => 'pic',
        'division_ids' => $divisions->pluck('id')->all(),
        'password' => 'tempPassword123',
    ], adminAuthHeader());

    $response->assertCreated();
    $user = User::query()->where('phone_number', '628123456788')->firstOrFail();

    expect($user->divisions()->pluck('divisions.id')->all())
        ->toEqualCanonicalizing($divisions->pluck('id')->all());
    expect($divisions->map->fresh()->every->is_active)->toBeTrue();

    $this->getJson('/api/admin/users?division_id='.$divisions->last()->id, adminAuthHeader())
        ->assertOk()
        ->assertJsonPath('data.0.id', $user->id)
        ->assertJsonCount(2, 'data.0.divisions');
});

test('admin cannot create spv', function () {
    $response = $this->postJson('/api/admin/users', [
        'name' => 'X',
        'phone_number' => '08111111111',
        'role' => 'spv',
        'password' => 'tempPassword123',
    ], adminAuthHeader());

    $response->assertStatus(422);
});

test('pic required division', function () {
    $response = $this->postJson('/api/admin/users', [
        'name' => 'Budi Santoso',
        'phone_number' => '08123456789',
        'role' => 'pic',
        'password' => 'tempPassword123',
    ], adminAuthHeader());

    $response->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['division_id']]);
});

test('deactivating last pic in division is hard blocked', function () {
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

    $response = $this->putJson('/api/admin/users/'.$pic->id, [
        'is_active' => false,
    ], adminAuthHeader());

    $response->assertStatus(422);
});

test('cannot delete user with active tickets', function () {
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

    $customer = Customer::create([
        'phone_number' => '628111111111',
        'name' => 'Andi',
    ]);

    Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'spv',
        'priority' => 'medium',
        'status' => 'open',
        'subject' => 'Test',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'running',
    ]);

    $response = $this->deleteJson('/api/admin/users/'.$pic->id, [], adminAuthHeader());
    $response->assertStatus(422);
});
