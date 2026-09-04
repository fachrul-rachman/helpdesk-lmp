<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function subcategoryDivision(array $overrides = []): Division
{
    return Division::create(array_merge([
        'name' => fake()->unique()->word(),
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

function subcategoryUser(string $role, ?Division $division = null): User
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

function subcategoryHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
}

function subcategoryTicket(Division $division, User $assignee): Ticket
{
    $customer = Customer::create([
        'phone_number' => '628'.fake()->unique()->numerify(str_repeat('#', 10)),
        'name' => 'Customer',
    ]);

    return Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $assignee->id,
        'created_by' => 'ai',
        'priority' => 'medium',
        'status' => 'new',
        'subject' => 'Test klasifikasi',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ]);
}

test('ticket lama tetap valid dengan dua subkategori null', function () {
    $division = subcategoryDivision();
    $pic = subcategoryUser('pic', $division);
    $ticket = subcategoryTicket($division, $pic);

    $this->getJson("/api/tickets/{$ticket->id}", subcategoryHeaders($pic))
        ->assertOk()
        ->assertJsonPath('data.global_subcategory', null)
        ->assertJsonPath('data.division_subcategory', null);
});

test('admin mengelola subkategori global dan divisi', function () {
    $admin = subcategoryUser('admin');
    $division = subcategoryDivision();

    $this->postJson('/api/admin/ticket-subcategories', [
        'name' => 'Complaint',
        'division_id' => null,
        'is_active' => true,
    ], subcategoryHeaders($admin))->assertCreated();

    $this->postJson('/api/admin/ticket-subcategories', [
        'name' => 'Tomb Pecah',
        'division_id' => $division->id,
        'is_active' => true,
    ], subcategoryHeaders($admin))->assertCreated();

    $this->getJson('/api/admin/ticket-subcategories', subcategoryHeaders($admin))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('pilihan subkategori hanya berisi global dan divisi ticket yang aktif', function () {
    $division = subcategoryDivision();
    $otherDivision = subcategoryDivision();
    $pic = subcategoryUser('pic', $division);

    TicketSubcategory::create(['name' => 'Info', 'division_id' => null, 'is_active' => true]);
    TicketSubcategory::create(['name' => 'Tomb Pecah', 'division_id' => $division->id, 'is_active' => true]);
    TicketSubcategory::create(['name' => 'Milik Divisi Lain', 'division_id' => $otherDivision->id, 'is_active' => true]);
    TicketSubcategory::create(['name' => 'Nonaktif', 'division_id' => null, 'is_active' => false]);

    $this->getJson("/api/ticket-subcategories?division_id={$division->id}", subcategoryHeaders($pic))
        ->assertOk()
        ->assertJsonCount(1, 'data.global')
        ->assertJsonCount(1, 'data.division');
});

test('pic dapat mengubah dua subkategori dan scope divisi divalidasi', function () {
    $division = subcategoryDivision();
    $otherDivision = subcategoryDivision();
    $pic = subcategoryUser('pic', $division);
    $ticket = subcategoryTicket($division, $pic);
    $global = TicketSubcategory::create(['name' => 'Request', 'division_id' => null, 'is_active' => true]);
    $local = TicketSubcategory::create(['name' => 'Ganti Tulisan', 'division_id' => $division->id, 'is_active' => true]);
    $wrongLocal = TicketSubcategory::create(['name' => 'Salah Divisi', 'division_id' => $otherDivision->id, 'is_active' => true]);

    $this->patchJson("/api/tickets/{$ticket->id}/subcategories", [
        'global_subcategory_id' => $global->id,
        'division_subcategory_id' => $local->id,
    ], subcategoryHeaders($pic))
        ->assertOk()
        ->assertJsonPath('data.global_subcategory.id', $global->id)
        ->assertJsonPath('data.division_subcategory.id', $local->id);

    $local->update(['is_active' => false]);
    $this->patchJson("/api/tickets/{$ticket->id}/subcategories", [
        'global_subcategory_id' => $global->id,
        'division_subcategory_id' => $local->id,
    ], subcategoryHeaders($pic))->assertOk();

    $this->patchJson("/api/tickets/{$ticket->id}/subcategories", [
        'division_subcategory_id' => $wrongLocal->id,
    ], subcategoryHeaders($pic))->assertUnprocessable();
});

test('spv dapat membuat ticket manual dengan dua subkategori opsional', function () {
    $division = subcategoryDivision();
    subcategoryUser('pic', $division);
    $spv = subcategoryUser('spv');
    $global = TicketSubcategory::create(['name' => 'Complaint', 'division_id' => null, 'is_active' => true]);
    $local = TicketSubcategory::create(['name' => 'Tomb Pecah', 'division_id' => $division->id, 'is_active' => true]);

    $this->postJson('/api/spv/tickets', [
        'customer_phone_number' => '081234567890',
        'division_id' => $division->id,
        'subject' => 'Ticket manual',
        'priority' => 'medium',
        'global_subcategory_id' => $global->id,
        'division_subcategory_id' => $local->id,
    ], subcategoryHeaders($spv))
        ->assertCreated()
        ->assertJsonPath('data.global_subcategory.id', $global->id)
        ->assertJsonPath('data.division_subcategory.id', $local->id);
});

test('pindah divisi mengosongkan subkategori divisi namun mempertahankan global', function () {
    $division = subcategoryDivision();
    $destination = subcategoryDivision();
    $destinationPic = subcategoryUser('pic', $destination);
    $spv = subcategoryUser('spv');
    $ticket = subcategoryTicket($division, $spv);
    $global = TicketSubcategory::create(['name' => 'Info', 'division_id' => null, 'is_active' => true]);
    $local = TicketSubcategory::create(['name' => 'Tomb Pecah', 'division_id' => $division->id, 'is_active' => true]);
    $ticket->update([
        'global_subcategory_id' => $global->id,
        'division_subcategory_id' => $local->id,
    ]);

    $this->patchJson("/api/tickets/{$ticket->id}/division", [
        'division_id' => $destination->id,
        'assigned_to' => $destinationPic->id,
    ], subcategoryHeaders($spv))->assertOk();

    expect($ticket->fresh()->global_subcategory_id)->toBe($global->id)
        ->and($ticket->fresh()->division_subcategory_id)->toBeNull();
});
