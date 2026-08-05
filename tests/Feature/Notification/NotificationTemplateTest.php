<?php

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('notification templates build correct payloads', function () {
    config(['queue.default' => 'sync']);
    config([
        'services.meta_whatsapp.token' => 'test-token',
        'services.meta_whatsapp.phone_number_id' => '123',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
    ]);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

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

    $customer = Customer::create(['phone_number' => '6283333333333', 'name' => 'Andi']);
    $pic = User::factory()->create([
        'role' => 'pic',
        'division_id' => $division->id,
        'is_active' => true,
        'phone_number' => '6281111111111',
        'name' => 'Budi',
    ]);
    $spv = User::factory()->create([
        'role' => 'spv',
        'division_id' => null,
        'is_active' => true,
        'phone_number' => '6282222222222',
        'name' => 'SPV',
    ]);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'new',
        'subject' => 'Laptop tidak menyala',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ])->fresh(['customer', 'division', 'assignee']);

    $service = app(NotificationService::class);

    $service->sendTicketAssignedToAgent($pic, $ticket);
    $service->sendPicSlaFrWarning($pic, $ticket, 2);
    $service->sendPicSlaResolutionWarning($pic, $ticket, CarbonImmutable::parse('2026-01-05 10:00:00'));
    $service->sendPicTicketReopened($pic, $ticket);
    $service->sendSpvSlaFrOverdue($spv, $ticket);
    $service->sendSpvSlaResolutionOverdue($spv, $ticket, '2 jam');
    $service->sendCustomerPendingReminder($ticket, 60);
    $service->sendAiIntroduction($customer->phone_number, $customer->name ?? '');
    $service->sendSystemError($customer->phone_number, $customer->name ?? '');

    Http::assertSent(function ($request) {
        return ($request['type'] ?? null) === 'template'
            && ($request['template']['name'] ?? null) === 'ticket_assigned_to_agent'
            && (($request['template']['components'][0]['parameters'][1]['text'] ?? null) === 'Andi')
            && (($request['template']['components'][0]['parameters'][0]['parameter_name'] ?? null) === 'nomor_ticket');
    });

    Http::assertSent(fn ($request) => ($request['template']['name'] ?? null) === 'pic_sla_fr_warning');
    Http::assertSent(fn ($request) => ($request['template']['name'] ?? null) === 'pic_sla_resolution_warning');
    Http::assertSent(fn ($request) => ($request['template']['name'] ?? null) === 'pic_ticket_reopened');
    Http::assertSent(fn ($request) => ($request['template']['name'] ?? null) === 'spv_sla_fr_overdue');
    Http::assertSent(fn ($request) => ($request['template']['name'] ?? null) === 'spv_sla_resolution_overdue');
    Http::assertSent(fn ($request) => ($request['template']['name'] ?? null) === 'customer_pending_reminder');
    Http::assertSent(fn ($request) => ($request['template']['name'] ?? null) === 'ai_introduction');
    Http::assertSent(fn ($request) => ($request['template']['name'] ?? null) === 'system_error');
});

test('notification retry dispatched after 30 seconds once', function () {
    config(['queue.default' => 'database']);
    config([
        'services.meta_whatsapp.token' => 'test-token',
        'services.meta_whatsapp.phone_number_id' => '123',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
    ]);

    Bus::fake();

    // Jangan jalankan HTTP; cukup pastikan job ter-queue.
    app(NotificationService::class)->sendTemplate('6281111111111', 'pic_sla_fr_warning', ['A', 'B', 'C', '1']);

    Bus::assertDispatched(\App\Jobs\SendWhatsAppMessageJob::class, function ($job) {
        return $job->tries === 2 && (int) $job->backoff === 30;
    });
});

test('new ticket notification can also be copied to spv via setting', function () {
    config(['queue.default' => 'sync']);
    config([
        'services.meta_whatsapp.token' => 'test-token',
        'services.meta_whatsapp.phone_number_id' => '123',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
    ]);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    AppSetting::create([
        'key' => 'notify_spv_on_new_ticket',
        'value' => '1',
    ]);

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

    $customer = Customer::create(['phone_number' => '6283333333333', 'name' => 'Andi']);
    $pic = User::factory()->create([
        'role' => 'pic',
        'division_id' => $division->id,
        'is_active' => true,
        'phone_number' => '6281111111111',
        'name' => 'Budi',
    ]);
    User::factory()->create([
        'role' => 'spv',
        'division_id' => null,
        'is_active' => true,
        'phone_number' => '6282222222222',
        'name' => 'SPV',
    ]);

    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'new',
        'subject' => 'Laptop tidak menyala',
        'sla_fr_status' => 'running',
        'sla_resolution_status' => 'waiting',
    ])->fresh(['customer', 'division', 'assignee']);

    app(NotificationService::class)->sendTicketAssignedToAgent($pic, $ticket);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => ($request['to'] ?? null) === '6281111111111');
    Http::assertSent(fn ($request) => ($request['to'] ?? null) === '6282222222222');
});
