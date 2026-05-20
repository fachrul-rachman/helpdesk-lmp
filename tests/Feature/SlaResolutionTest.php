<?php

use App\Jobs\CheckPendingTicketsJob;
use App\Jobs\CheckSlaResolutionOverdueJob;
use App\Jobs\CheckSlaResolutionReminderJob;
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

function srSeedHours(string $divisionId): void
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

test('sla resolution reminder sent before deadline', function () {
    config(['queue.default' => 'sync']);

    $division = Division::create([
        'name' => 'Teknis',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 1,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false,
        'is_active' => true,
    ]);
    srSeedHours($division->id);

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

    Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'open',
        'subject' => 'Test',
        'sla_fr_status' => 'done',
        'sla_resolution_started_at' => CarbonImmutable::now(),
        'sla_resolution_deadline_at' => CarbonImmutable::now()->addMinutes(30),
        'sla_resolution_status' => 'running',
    ]);

    putenv('META_WA_TOKEN=test-token');
    putenv('META_WA_PHONE_NUMBER_ID=123');
    $_ENV['META_WA_TOKEN'] = 'test-token';
    $_ENV['META_WA_PHONE_NUMBER_ID'] = '123';

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200),
    ]);

    (new CheckSlaResolutionReminderJob())->handle(app(NotificationService::class));

    Http::assertSent(function ($request) {
        return ($request['template']['name'] ?? null) === 'pic_sla_resolution_warning';
    });
});

test('sla resolution overdue status updated and not sent twice', function () {
    config(['queue.default' => 'sync']);

    $division = Division::create([
        'name' => 'Teknis',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 1,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false,
        'is_active' => true,
    ]);
    srSeedHours($division->id);

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
        'status' => 'open',
        'subject' => 'Test',
        'sla_fr_status' => 'done',
        'sla_resolution_started_at' => CarbonImmutable::now()->subHours(5),
        'sla_resolution_deadline_at' => CarbonImmutable::now()->subMinute(),
        'sla_resolution_status' => 'running',
    ]);

    putenv('META_WA_TOKEN=test-token');
    putenv('META_WA_PHONE_NUMBER_ID=123');
    $_ENV['META_WA_TOKEN'] = 'test-token';
    $_ENV['META_WA_PHONE_NUMBER_ID'] = '123';

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200),
    ]);

    (new CheckSlaResolutionOverdueJob())->handle(app(NotificationService::class));
    (new CheckSlaResolutionOverdueJob())->handle(app(NotificationService::class));

    expect($ticket->fresh()->sla_resolution_status)->toBe('overdue');
    Http::assertSentCount(1);
});

test('pending auto close after 24 hours and reminder at 23 hours', function () {
    config(['queue.default' => 'sync']);

    $division = Division::create([
        'name' => 'Teknis',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 1,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false,
        'is_active' => true,
    ]);
    srSeedHours($division->id);

    $customer = Customer::create(['phone_number' => '6285555555555', 'name' => 'Andi']);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'low',
        'status' => 'pending',
        'subject' => 'Test',
        'sla_fr_status' => 'done',
        'sla_resolution_started_at' => CarbonImmutable::parse('2026-01-05 09:00:00'),
        'sla_resolution_deadline_at' => CarbonImmutable::parse('2026-01-06 09:00:00'),
        'sla_resolution_status' => 'paused',
        'sla_resolution_paused_at' => CarbonImmutable::parse('2026-01-05 10:00:00'),
    ]);

    putenv('META_WA_TOKEN=test-token');
    putenv('META_WA_PHONE_NUMBER_ID=123');
    $_ENV['META_WA_TOKEN'] = 'test-token';
    $_ENV['META_WA_PHONE_NUMBER_ID'] = '123';

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200),
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-06 09:00:00')); // 23 jam sejak 10:00
    (new CheckPendingTicketsJob())->handle(app(\App\Services\TicketService::class), app(NotificationService::class));
    Http::assertSent(function ($request) {
        return ($request['template']['name'] ?? null) === 'customer_pending_reminder';
    });

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-06 10:00:00')); // 24 jam
    (new CheckPendingTicketsJob())->handle(app(\App\Services\TicketService::class), app(NotificationService::class));
    expect($ticket->fresh()->status)->toBe('closed');
});

test('customer reply on pending reopens ticket', function () {
    $division = Division::create([
        'name' => 'Teknis',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 1,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false,
        'is_active' => true,
    ]);
    srSeedHours($division->id);

    putenv('META_WA_APP_SECRET=test-secret');
    $_ENV['META_WA_APP_SECRET'] = 'test-secret';

    $customer = Customer::create(['phone_number' => '628999999999', 'name' => 'Andi']);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'low',
        'status' => 'pending',
        'subject' => 'Test',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'paused',
        'sla_resolution_paused_at' => CarbonImmutable::parse('2026-01-05 10:00:00'),
        'sla_resolution_deadline_at' => CarbonImmutable::parse('2026-01-06 10:00:00'),
    ]);

    $payloadArr = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA_ID',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => [
                        'display_phone_number' => '628000000000',
                        'phone_number_id' => 'PHONE_NUMBER_ID',
                    ],
                    'contacts' => [[
                        'profile' => ['name' => 'Andi'],
                        'wa_id' => '628999999999',
                    ]],
                    'messages' => [[
                        'id' => 'wamid.pending',
                        'from' => '628999999999',
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => 'Halo'],
                    ]],
                ],
            ]],
        ]],
    ];

    $payload = json_encode($payloadArr, JSON_UNESCAPED_SLASHES);
    $signature = 'sha256=' . hash_hmac('sha256', $payload, 'test-secret');

    $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $payload)->assertOk();

    expect($ticket->fresh()->status)->toBe('open');
});
