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

    $start = CarbonImmutable::parse('2026-01-05 09:00:00', 'Asia/Jakarta');
    $deadline = $service->calculateDeadline($start, 5, $division->id);

    expect($deadline->format('Y-m-d H:i'))->toBe('2026-01-05 09:05');
});

test('deadline uses Jakarta working hours when application timestamps are UTC', function () {
    $division = slaDivision();
    seedWorkingHours($division->id);

    $service = app(SlaService::class);

    // 04:33 UTC is 11:33 in Jakarta and must be treated as inside working hours.
    $start = CarbonImmutable::parse('2026-06-18 04:33:00', 'UTC');
    $deadline = $service->calculateDeadline($start, 5, $division->id);

    expect($deadline->getTimezone()->getName())->toBe('UTC')
        ->and($deadline->format('Y-m-d H:i'))->toBe('2026-06-18 04:38')
        ->and($deadline->timezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-06-18 11:38');
});

test('deadline skips non working hours', function () {
    $division = slaDivision();
    seedWorkingHours($division->id);

    $service = app(SlaService::class);

    $start = CarbonImmutable::parse('2026-01-05 18:00:00', 'Asia/Jakarta');
    $deadline = $service->calculateDeadline($start, 5, $division->id);

    expect($deadline->format('Y-m-d H:i'))->toBe('2026-01-06 08:05');
});

test('deadline skips weekend', function () {
    $division = slaDivision();
    seedWorkingHours($division->id);

    $service = app(SlaService::class);

    $start = CarbonImmutable::parse('2026-01-02 16:58:00', 'Asia/Jakarta'); // Friday
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

    $start = CarbonImmutable::parse('2026-01-01 16:58:00', 'Asia/Jakarta'); // Thursday
    $deadline = $service->calculateDeadline($start, 5, $division->id);

    expect($deadline->format('Y-m-d H:i'))->toBe('2026-01-05 08:03'); // Friday holiday -> Monday
});

test('elapsed working minutes calculation', function () {
    $division = slaDivision();
    seedWorkingHours($division->id);

    $service = app(SlaService::class);

    $start = CarbonImmutable::parse('2026-01-02 16:00:00', 'Asia/Jakarta'); // Friday
    $end = CarbonImmutable::parse('2026-01-05 09:00:00', 'Asia/Jakarta'); // Monday

    $elapsed = $service->calculateElapsedWorkingMinutes($start, $end, $division->id);
    expect($elapsed)->toBe(120);
});

test('elapsed working minutes use Jakarta working hours for UTC timestamps', function () {
    $division = slaDivision();
    seedWorkingHours($division->id);

    $service = app(SlaService::class);

    // Friday 16:00 WIB until Monday 09:00 WIB spans two working hours.
    $start = CarbonImmutable::parse('2026-01-02 09:00:00', 'UTC');
    $end = CarbonImmutable::parse('2026-01-05 02:00:00', 'UTC');

    expect($service->calculateElapsedWorkingMinutes($start, $end, $division->id))->toBe(120);
});

test('elapsed working minutes handle partial minutes without deprecation', function () {
    $division = slaDivision();
    seedWorkingHours($division->id);

    $service = app(SlaService::class);
    $start = CarbonImmutable::parse('2026-06-18 04:33:35', 'UTC');
    $end = CarbonImmutable::parse('2026-06-18 06:50:30', 'UTC');

    set_error_handler(function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    }, E_DEPRECATED);

    try {
        expect($service->calculateElapsedWorkingMinutes($start, $end, $division->id))->toBe(136);
    } finally {
        restore_error_handler();
    }
});

test('pause adds correct duration to deadline', function () {
    $division = slaDivision(['sla_resolution_value' => 2, 'sla_resolution_unit' => 'hours']);
    seedWorkingHours($division->id);

    $service = app(SlaService::class);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 02:00:00', 'UTC')); // 09:00 WIB

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

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 02:30:00', 'UTC')); // 09:30 WIB
    $service->pauseSla($ticket->id, 'pending');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-05 03:30:00', 'UTC')); // 10:30 WIB
    $service->resumeSla($ticket->id);

    $ticket->refresh();
    expect((int) $ticket->sla_resolution_paused_duration)->toBe(3600);
    expect(CarbonImmutable::parse($ticket->sla_resolution_deadline_at)->timezone('Asia/Jakarta')->format('H:i'))->toBe('12:00');
});
