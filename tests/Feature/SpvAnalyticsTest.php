<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\DivisionWorkingHour;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function saAuth(User $user): array
{
    return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user)];
}

function saSeedHours(string $divisionId): void
{
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $d) {
        DivisionWorkingHour::create([
            'division_id' => $divisionId,
            'day_of_week' => $d,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'is_active' => true,
        ]);
    }
    foreach (['saturday', 'sunday'] as $d) {
        DivisionWorkingHour::create([
            'division_id' => $divisionId,
            'day_of_week' => $d,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'is_active' => false,
        ]);
    }
}

test('GET /api/spv/analytics mengembalikan ringkasan ticket dan rata-rata SLA FR', function () {
    $spv = User::factory()->create(['role' => 'spv', 'division_id' => null, 'is_active' => true]);

    $d1 = Division::create([
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
    $d2 = Division::create([
        'name' => 'Billing',
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
    saSeedHours($d1->id);
    saSeedHours($d2->id);

    $c = Customer::create(['phone_number' => '6281111111111', 'name' => 'Andi']);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 09:00:00'));
    Ticket::create([
        'customer_id' => $c->id,
        'division_id' => $d1->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'open',
        'subject' => 'T1',
        'sla_fr_status' => 'done',
        'sla_fr_started_at' => CarbonImmutable::now(),
        'sla_fr_completed_at' => CarbonImmutable::now()->addMinutes(5),
        'sla_resolution_status' => 'running',
    ]);
    Ticket::create([
        'customer_id' => $c->id,
        'division_id' => $d2->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'low',
        'status' => 'pending',
        'subject' => 'T2',
        'sla_fr_status' => 'done',
        'sla_fr_started_at' => CarbonImmutable::now(),
        'sla_fr_completed_at' => CarbonImmutable::now()->addMinutes(3),
        'sla_resolution_status' => 'paused',
    ]);
    Ticket::create([
        'customer_id' => $c->id,
        'division_id' => $d2->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'low',
        'status' => 'closed',
        'subject' => 'T3',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ]);

    $resp = $this->getJson('/api/spv/analytics', saAuth($spv));
    $resp->assertOk();

    expect($resp->json('tickets.total'))->toBe(3);
    expect($resp->json('tickets.active'))->toBe(2);

    expect($resp->json('sla_fr.average_minutes_overall'))->toBe(4);
});
