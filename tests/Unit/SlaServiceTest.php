<?php

use App\Models\Division;
use App\Models\DivisionWorkingHour;
use App\Models\Customer;
use App\Models\PublicHoliday;
use App\Models\Ticket;
use App\Services\SlaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function slaDivision(array $overrides = []): Division
{
    return Division::create(array_merge([
        'name' => 'Teknis',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 1,
        'sla_resolution_unit' => 'hours',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false,
        'is_active' => true,
    ], $overrides));
}

function seedWorkingHours(string $divisionId): void
{
    $days = [
        ['day_of_week' => 'monday', 'is_active' => true],
        ['day_of_week' => 'tuesday', 'is_active' => true],
        ['day_of_week' => 'wednesday', 'is_active' => true],
        ['day_of_week' => 'thursday', 'is_active' => true],
        ['day_of_week' => 'friday', 'is_active' => true],
        ['day_of_week' => 'saturday', 'is_active' => false],
        ['day_of_week' => 'sunday', 'is_active' => false],
    ];

    foreach ($days as $day) {
        DivisionWorkingHour::create([
            'division_id' => $divisionId,
            'day_of_week' => $day['day_of_week'],
            'start_time' => '08:00',
            'end_time' => '17:00',
            'is_active' => $day['is_active'],
        ]);
    }
}

test('deadline calculated within working hours', function () {
    $division = slaDivision();
    seedWorkingHours($division->id);

    $service = app(SlaService::class);

    $start = CarbonImmutable::parse('2026-01-05 09:00:00');
    $deadline = $service->calculateDeadline($start, 5, $division->id);

    expect($deadline->format('Y-m-d H:i'))->toBe('2026-01-05 09:05');
});

test('deadline skips non working hours', function () {
    $division = slaDivision();
    seedWorkingHours($division->id);

    $service = app(SlaService::class);

    $start = CarbonImmutable::parse('2026-01-05 18:00:00');
    $deadline = $service->calculateDeadline($start, 5, $division->id);

    expect($deadline->format('Y-m-d H:i'))->toBe('2026-01-06 08:05');
});

test('deadline skips weekend', function () {
    $division = slaDivision();
    seedWorkingHours($division->id);

    $service = app(SlaService::class);

    $start = CarbonImmutable::parse('2026-01-02 16:58:00'); // Friday
    $deadline = $service->calculateDeadline($start, 5, $division->id);

    expect($deadline->format('Y-m-d H:i'))->toBe('2026-01-05 08:03'); // Monday
});

test('deadline skips public holiday', function () {
    $division = slaDivision();
    seedWorkingHours($division->id);

    PublicHoliday::create([
        'date' => '2026-01-02',
        'name' => 'Libur',
        'year' => 2026,
    ]);

    $service = app(SlaService::class);

    $start = CarbonImmutable::parse('2026-01-01 16:58:00'); // Thursday
    $deadline = $service->calculateDeadline($start, 5, $division->id);

    expect($deadline->format('Y-m-d H:i'))->toBe('2026-01-05 08:03'); // Friday holiday -> Monday
});

test('elapsed working minutes calculation', function () {
    $division = slaDivision();
    seedWorkingHours($division->id);

    $service = app(SlaService::class);

    $start = CarbonImmutable::parse('2026-01-02 16:00:00'); // Friday
    $end = CarbonImmutable::parse('2026-01-05 09:00:00'); // Monday

    $elapsed = $service->calculateElapsedWorkingMinutes($start, $end, $division->id);
    expect($elapsed)->toBe(120);
});

test('pause adds correct duration to deadline', function () {
    $division = slaDivision(['sla_resolution_value' => 2, 'sla_resolution_unit' => 'hours']);
    seedWorkingHours($division->id);

    $service = app(SlaService::class);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 09:00:00'));

    $customer = Customer::create(['phone_number' => '6285550000000', 'name' => 'Andi']);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'medium',
        'status' => 'open',
        'subject' => 'Test',
        'sla_fr_status' => 'done',
        'sla_resolution_started_at' => CarbonImmutable::now(),
        'sla_resolution_deadline_at' => $service->calculateDeadline(CarbonImmutable::now(), 120, $division->id),
        'sla_resolution_status' => 'running',
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 09:30:00'));
    $service->pauseSla($ticket->id, 'pending');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 10:30:00'));
    $service->resumeSla($ticket->id);

    $ticket->refresh();
    expect((int) $ticket->sla_resolution_paused_duration)->toBe(3600);
    expect(CarbonImmutable::parse($ticket->sla_resolution_deadline_at)->format('H:i'))->toBe('12:00');
});
