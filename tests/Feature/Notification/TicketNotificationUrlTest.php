<?php

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Abort before exercising the feature if the test environment is not isolated.
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');

    config([
        'queue.default' => 'sync',
        'services.meta_whatsapp.token' => 'test-token',
        'services.meta_whatsapp.phone_number_id' => '123',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
        'services.meta_whatsapp.ticket_url_templates' => [],
    ]);
    Http::preventStrayRequests();
    Http::fake(['https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);

    $this->division = Division::create([
        'name' => 'Pelayanan', 'description' => 'Test', 'handles' => 'Test',
        'not_handles' => 'Test', 'ticket_examples' => 'Test',
        'sla_resolution_value' => 3, 'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12, 'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false, 'is_active' => true,
    ]);
    $this->pic = User::factory()->create(['role' => 'pic', 'name' => 'PIC', 'phone_number' => '6281100000001', 'is_active' => true]);
    $this->spv = User::factory()->create(['role' => 'spv', 'name' => 'SPV', 'phone_number' => '6281100000002', 'is_active' => true]);
    $customer = Customer::create(['name' => 'Customer', 'phone_number' => '6281100000003']);
    $this->ticket = Ticket::create([
        'customer_id' => $customer->id, 'division_id' => $this->division->id,
        'assigned_to' => $this->pic->id, 'created_by' => 'ai', 'priority' => 'high',
        'status' => 'open', 'subject' => 'Permintaan customer', 'notes' => 'Tindak lanjut',
        'sla_fr_status' => 'done', 'sla_resolution_status' => 'running',
    ])->fresh(['customer', 'division', 'assignee']);
});

dataset('team ticket notifications', [
    ['sendTicketAssignedToAgent', 'ticket_assigned_to_agent', 'pic'],
    ['sendTicketAssignedToAgent', 'ticket_assigned_to_agent', 'spv'],
    ['sendPicTicketReopened', 'ticket_on_progress_opened', 'pic'],
    ['sendPicTicketReopened', 'ticket_on_progress_opened', 'spv'],
    ['sendPicSlaFrWarning', 'sla_first_response_warning', 'pic'],
    ['sendPicSlaResolutionWarning', 'sla_resolution_warning', 'pic'],
    ['sendSpvSlaFrOverdue', 'sla_first_response_breached', 'spv'],
    ['sendSpvSlaResolutionOverdue', 'sla_resolution_breached', 'spv'],
]);

function sendTicketNotificationForTest(NotificationService $service, string $method, User $recipient, Ticket $ticket): void
{
    $args = match ($method) {
        'sendPicSlaFrWarning' => [5],
        'sendPicSlaResolutionWarning' => [CarbonImmutable::parse('2026-10-23 10:00:00')],
        'sendSpvSlaResolutionOverdue' => ['2 jam'],
        default => [],
    };
    $service->$method($recipient, $ticket, ...$args);
}

test('ticket URL is opt in per template and preserves the existing body', function (string $method, string $template, string $role) {
    $service = app(NotificationService::class);
    $recipient = $this->$role;

    sendTicketNotificationForTest($service, $method, $recipient, $this->ticket);
    config(['services.meta_whatsapp.ticket_url_templates' => ['not_this_template']]);
    sendTicketNotificationForTest($service, $method, $recipient, $this->ticket);
    config(['services.meta_whatsapp.ticket_url_templates' => [$template]]);
    sendTicketNotificationForTest($service, $method, $recipient, $this->ticket);

    Http::assertSentCount(3);
    $payloads = Http::recorded()->map(fn ($entry) => $entry[0]->data())->all();
    expect($payloads[1])->toBe($payloads[0]);
    $components = $payloads[2]['template']['components'];
    expect($components[0])->toBe($payloads[0]['template']['components'][0]);
    expect($components[1] ?? null)->toBe([
        'type' => 'button', 'sub_type' => 'url', 'index' => '0',
        'parameters' => [['type' => 'text', 'text' => "{$role}/tickets/{$this->ticket->id}"]],
    ]);
    expect($payloads[2]['template']['name'])->toBe($template);
})->with('team ticket notifications');

test('SPV copy uses the SPV ticket path instead of the PIC path', function () {
    AppSetting::create(['key' => 'notify_spv_on_new_ticket', 'value' => '1']);
    config(['services.meta_whatsapp.ticket_url_templates' => ['ticket_assigned_to_agent']]);
    app(NotificationService::class)->sendTicketAssignedToAgent($this->pic, $this->ticket);

    Http::assertSentCount(2);
    foreach (['pic', 'spv'] as $role) {
        Http::assertSent(fn ($request) => $request['to'] === $this->$role->phone_number
            && ($request['template']['components'][1]['parameters'][0]['text'] ?? null) === "{$role}/tickets/{$this->ticket->id}");
    }
});

test('generic URL templates retain their existing URL parameter', function () {
    app(NotificationService::class)->sendTemplateWithUrlButton('6281100000003', 'satisfaction_review', [], '?ticket_id=test&signature=test');

    Http::assertSent(fn ($request) => $request['template']['components'] === [[
        'type' => 'button', 'sub_type' => 'url', 'index' => '0',
        'parameters' => [['type' => 'text', 'text' => '?ticket_id=test&signature=test']],
    ]]);
});

test('customer templates do not acquire team ticket buttons', function () {
    config(['services.meta_whatsapp.ticket_url_templates' => ['resume_customer_conversation', 'cust_ticket_will_be_closed']]);
    $service = app(NotificationService::class);
    $service->sendResumeCustomerConversation('6281100000003', 'Customer', 'Permintaan customer');
    $service->sendCustomerPendingReminder($this->ticket);
    Http::assertSentCount(2);
    foreach (Http::recorded() as [$request]) {
        expect(array_column($request['template']['components'], 'type'))->toBe(['body']);
    }
});
