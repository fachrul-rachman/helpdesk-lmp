<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function scAuth(User $user): array
{
    return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user)];
}

test('GET /api/spv/conversations mengembalikan list percakapan dengan last_message dan active_ticket', function () {
    $spv = User::factory()->create(['role' => 'spv', 'division_id' => null, 'is_active' => true]);

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

    $c1 = Customer::create(['phone_number' => '6281111111111', 'name' => 'Andi']);
    $c2 = Customer::create(['phone_number' => '6282222222222', 'name' => 'Budi']);

    $t = Ticket::create([
        'customer_id' => $c1->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'open',
        'subject' => 'Tiket 1',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 09:00:00'));
    Message::create([
        'ticket_id' => $t->id,
        'customer_id' => $c1->id,
        'sender_type' => 'customer',
        'sender_id' => null,
        'content' => 'Halo',
        'wa_message_id' => 'wa-1',
        'created_at' => CarbonImmutable::now()->subMinute(),
    ]);
    Message::create([
        'ticket_id' => null,
        'customer_id' => $c2->id,
        'sender_type' => 'customer',
        'sender_id' => null,
        'content' => 'Tanya jam kerja',
        'wa_message_id' => 'wa-2',
        'created_at' => CarbonImmutable::now(),
    ]);

    $resp = $this->getJson('/api/spv/conversations?per_page=20&page=1', scAuth($spv));
    $resp->assertOk()->assertJsonPath('meta.total', 2);

    $byId = collect($resp->json('data'))->keyBy('customer.id');

    expect($byId->has($c1->id))->toBeTrue();
    expect($byId->has($c2->id))->toBeTrue();

    expect($byId[$c2->id]['last_message']['content'])->toBe('Tanya jam kerja');
    expect($byId[$c2->id]['active_ticket'])->toBeNull();

    expect($byId[$c1->id]['active_ticket']['id'])->toBe($t->id);
});

test('filter has_ticket bekerja', function () {
    $spv = User::factory()->create(['role' => 'spv', 'division_id' => null, 'is_active' => true]);
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

    $c1 = Customer::create(['phone_number' => '6281111111111', 'name' => 'Andi']);
    $c2 = Customer::create(['phone_number' => '6282222222222', 'name' => 'Budi']);

    Ticket::create([
        'customer_id' => $c1->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'open',
        'subject' => 'Tiket 1',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    Message::create([
        'ticket_id' => null,
        'customer_id' => $c1->id,
        'sender_type' => 'customer',
        'sender_id' => null,
        'content' => 'x',
        'wa_message_id' => 'wa-1',
        'created_at' => now(),
    ]);
    Message::create([
        'ticket_id' => null,
        'customer_id' => $c2->id,
        'sender_type' => 'customer',
        'sender_id' => null,
        'content' => 'y',
        'wa_message_id' => 'wa-2',
        'created_at' => now(),
    ]);

    $this->getJson('/api/spv/conversations?has_ticket=true', scAuth($spv))
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/spv/conversations?has_ticket=false', scAuth($spv))
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});
