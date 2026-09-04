<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function locationDivision(): Division
{
    return Division::create([
        'name' => 'Tomb',
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

function locationUser(string $role, ?Division $division = null): User
{
    $user = User::factory()->create([
        'role' => $role,
        'division_id' => $division?->id,
        'is_active' => true,
    ]);

    if ($role === 'pic' && $division) {
        $user->divisions()->sync([$division->id]);
    }

    return $user;
}

function locationHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function locationTicket(Division $division, User $pic): Ticket
{
    $customer = Customer::create(['phone_number' => '628123450001', 'name' => 'Andi']);

    return Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'medium',
        'status' => 'new',
        'subject' => 'Data makam',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ]);
}

test('field lokasi ticket lama null dan dapat diisi lalu dikosongkan pic', function () {
    $division = locationDivision();
    $pic = locationUser('pic', $division);
    $ticket = locationTicket($division, $pic);

    $this->getJson("/api/tickets/{$ticket->id}", locationHeaders($pic))
        ->assertOk()
        ->assertJsonPath('data.site', null)
        ->assertJsonPath('data.zone', null)
        ->assertJsonPath('data.lot_number', null);

    $this->patchJson("/api/tickets/{$ticket->id}/location", [
        'site' => 'Lestari Memorial Park',
        'zone' => 'Zone B',
        'lot_number' => 'B-12-08',
    ], locationHeaders($pic))
        ->assertOk()
        ->assertJsonPath('data.site', 'Lestari Memorial Park')
        ->assertJsonPath('data.zone', 'Zone B')
        ->assertJsonPath('data.lot_number', 'B-12-08');

    $this->patchJson("/api/tickets/{$ticket->id}/location", [
        'site' => null,
        'zone' => null,
        'lot_number' => null,
    ], locationHeaders($pic))->assertOk();

    expect($ticket->fresh()->site)->toBeNull()
        ->and($ticket->fresh()->zone)->toBeNull()
        ->and($ticket->fresh()->lot_number)->toBeNull();
});

test('spv dapat membuat ticket manual dengan field lokasi opsional', function () {
    $division = locationDivision();
    locationUser('pic', $division);
    $spv = locationUser('spv');

    $this->postJson('/api/spv/tickets', [
        'customer_phone_number' => '08123450002',
        'division_id' => $division->id,
        'subject' => 'Perubahan tulisan makam',
        'priority' => 'medium',
        'site' => 'LMP Karawang',
        'zone' => 'Zone C',
        'lot_number' => 'C-07-02',
    ], locationHeaders($spv))
        ->assertCreated()
        ->assertJsonPath('data.site', 'LMP Karawang')
        ->assertJsonPath('data.zone', 'Zone C')
        ->assertJsonPath('data.lot_number', 'C-07-02');
});

test('pic dan spv dapat mengubah judul ticket', function () {
    $division = locationDivision();
    $pic = locationUser('pic', $division);
    $spv = locationUser('spv');
    $ticket = locationTicket($division, $pic);

    $this->patchJson("/api/tickets/{$ticket->id}/subject", [
        'subject' => 'Judul dari PIC',
    ], locationHeaders($pic))
        ->assertOk()
        ->assertJsonPath('data.subject', 'Judul dari PIC');

    $this->patchJson("/api/tickets/{$ticket->id}/subject", [
        'subject' => 'Judul dari SPV',
    ], locationHeaders($spv))
        ->assertOk()
        ->assertJsonPath('data.subject', 'Judul dari SPV');

    $this->patchJson("/api/tickets/{$ticket->id}/subject", [
        'subject' => '',
    ], locationHeaders($pic))->assertUnprocessable();
});
