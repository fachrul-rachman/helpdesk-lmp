<?php

use App\Jobs\CheckSlaFrOverdueJob;
use App\Jobs\CheckSlaFrReminderJob;
use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\Division;
use App\Models\DivisionWorkingHour;
use App\Models\Ticket;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function slaSeedDivisionHours(string $divisionId): void
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

test('sla fr reminder sent before deadline', function () {
    config(['queue.default' => 'sync']);

    AppSetting::upsert([
        ['key' => 'sla_fr_duration_minutes', 'value' => '5'],
        ['key' => 'sla_fr_reminder_minutes', 'value' => '3'],
    ], ['key'], ['value']);

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
    slaSeedDivisionHours($division->id);

    $pic = User::factory()->create([
        'role' => 'pic',
        'division_id' => $division->id,
        'password' => Hash::make('secret123'),
        'is_active' => true,
        'phone_number' => '6281111111111',
    ]);
    User::factory()->create([
        'role' => 'spv',
        'division_id' => null,
        'password' => Hash::make('secret123'),
        'is_active' => true,
        'phone_number' => '6282222222222',
    ]);

    $customer = Customer::create(['phone_number' => '6283333333333', 'name' => 'Andi']);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 09:00:00'));

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'new',
        'subject' => 'Test',
        'sla_fr_started_at' => CarbonImmutable::now(),
        'sla_fr_deadline_at' => CarbonImmutable::now()->addMinutes(3),
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ]);

    config([
        'services.meta_whatsapp.token' => 'test-token',
        'services.meta_whatsapp.phone_number_id' => '123',
    ]);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200),
    ]);

    (new CheckSlaFrReminderJob())->handle(app(NotificationService::class));

    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), '/123/messages')
            && ($request['template']['name'] ?? null) === 'pic_sla_fr_warning';
    });
});

test('sla fr overdue status updated and not sent twice', function () {
    config(['queue.default' => 'sync']);

    AppSetting::upsert([
        ['key' => 'sla_fr_duration_minutes', 'value' => '5'],
        ['key' => 'sla_fr_reminder_minutes', 'value' => '3'],
    ], ['key'], ['value']);

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
    slaSeedDivisionHours($division->id);

    User::factory()->create([
        'role' => 'spv',
        'division_id' => null,
        'password' => Hash::make('secret123'),
        'is_active' => true,
        'phone_number' => '6282222222222',
        'name' => 'SPV',
    ]);

    $customer = Customer::create(['phone_number' => '6284444444444', 'name' => 'Andi']);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 10:00:00'));

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'new',
        'subject' => 'Test',
        'sla_fr_started_at' => CarbonImmutable::now()->subMinutes(10),
        'sla_fr_deadline_at' => CarbonImmutable::now()->subMinute(),
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ]);

    config([
        'services.meta_whatsapp.token' => 'test-token',
        'services.meta_whatsapp.phone_number_id' => '123',
    ]);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200),
    ]);

    (new CheckSlaFrOverdueJob())->handle(app(NotificationService::class));
    (new CheckSlaFrOverdueJob())->handle(app(NotificationService::class));

    expect($ticket->fresh()->sla_fr_status)->toBe('overdue');

    Http::assertSentCount(1);
});
