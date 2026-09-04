<?php

namespace Tests\Unit;

use App\Events\MessageSent;
use App\Events\SlaWarning;
use App\Events\TicketAssigned;
use App\Events\TicketStatusChanged;
use App\Models\Ticket;
use App\Models\TicketTakeoverRequest;
use App\Support\PushEndpoint;
use App\Support\TicketPushNotification;
use PHPUnit\Framework\TestCase;

// Run with --no-configuration --bootstrap vendor/autoload.php: no Laravel/DB bootstrap.
class TicketPushNotificationTest extends TestCase
{
    public function test_only_customer_messages_and_exact_reopen_transition_trigger_push(): void
    {
        self::assertSame('message', TicketPushNotification::kind(new MessageSent(['sender_type' => 'customer'])));
        foreach (['pic', 'spv', 'ai', 'system'] as $sender) {
            self::assertNull(TicketPushNotification::kind(new MessageSent(['sender_type' => $sender])));
        }
        self::assertSame('assigned', TicketPushNotification::kind(new TicketAssigned([])));
        self::assertSame('reopened', TicketPushNotification::kind(new TicketStatusChanged(['from_status' => 'on_progress', 'to_status' => 'open'])));
        self::assertNull(TicketPushNotification::kind(new TicketStatusChanged(['from_status' => 'pending', 'to_status' => 'open'])));
        self::assertSame('sla_fr_reminder', TicketPushNotification::kind(new SlaWarning(['sla_type' => 'fr', 'warning_type' => 'reminder'])));
        self::assertSame('sla_resolution_overdue', TicketPushNotification::kind(new SlaWarning(['sla_type' => 'resolution', 'warning_type' => 'overdue'])));
        self::assertNull(TicketPushNotification::kind(new SlaWarning(['sla_type' => 'unknown'])));
    }

    public function test_takeover_owner_wins_but_closed_or_stale_takeover_does_not(): void
    {
        $ticket = new Ticket(['assigned_to' => 'pic-1']);
        $ticket->setRelation('takeoverRequest', null);
        self::assertSame('pic-1', TicketPushNotification::recipientId($ticket));
        $takeover = new TicketTakeoverRequest(['status' => 'approved', 'requested_by' => 'pic-1', 'approved_by' => 'spv-1']);
        $ticket->setRelation('takeoverRequest', $takeover);
        self::assertSame('spv-1', TicketPushNotification::recipientId($ticket));
        $takeover->status = 'closed';
        self::assertSame('pic-1', TicketPushNotification::recipientId($ticket));
        $takeover->status = 'approved';
        $ticket->assigned_to = 'pic-2';
        self::assertSame('pic-2', TicketPushNotification::recipientId($ticket));
        $ticket->assigned_to = null;
        self::assertNull(TicketPushNotification::recipientId($ticket));
    }

    public function test_payload_contains_only_generic_text_and_safe_ticket_link(): void
    {
        $ticket = new Ticket(['ticket_number' => 'T26-00042', 'subject' => 'PRIVATE CUSTOMER TEXT', 'notes' => 'SECRET']);
        $ticket->id = '1234';
        $payload = TicketPushNotification::payload($ticket, 'spv', 'message', 'event-1');
        self::assertSame('Ticket T26-00042', $payload['title']);
        self::assertSame('/app/spv/tickets/1234', $payload['url']);
        self::assertSame('Pesan baru dari customer.', $payload['body']);
        self::assertStringNotContainsString('PRIVATE', json_encode($payload));
        self::assertStringNotContainsString('SECRET', json_encode($payload));
    }

    public function test_push_endpoints_reject_ssrf_and_lookalike_domains(): void
    {
        foreach (['https://fcm.googleapis.com/fcm/send/abc', 'https://updates.push.services.mozilla.com/wpush/v2/abc', 'https://web.push.apple.com/abc', 'https://wns2-db5p.notify.windows.com/w/?token=abc'] as $endpoint) {
            self::assertTrue(PushEndpoint::allowed($endpoint), $endpoint);
        }
        foreach (['http://fcm.googleapis.com/a', 'https://127.0.0.1/a', 'https://fcm.googleapis.com.evil.test/a', 'https://evilnotify.windows.com/a', 'https://user@fcm.googleapis.com/a', 'https://fcm.googleapis.com:444/a', 'https://fcm.googleapis.com/a#b', 'https://localhost/a'] as $endpoint) {
            self::assertFalse(PushEndpoint::allowed($endpoint), $endpoint);
        }
    }

    public function test_delayed_sla_notifications_are_dropped_after_pause_or_deadline_change(): void
    {
        $ticket = (new Ticket)->setDateFormat('Y-m-d H:i:s')->fill(['status' => 'open', 'sla_resolution_status' => 'running', 'sla_resolution_deadline_at' => '2026-09-03 12:00:00']);
        self::assertTrue(TicketPushNotification::relevant($ticket, 'sla_resolution_reminder', '2026-09-03T12:00:00Z'));
        self::assertFalse(TicketPushNotification::relevant($ticket, 'sla_resolution_reminder', '2026-09-03T13:00:00Z'));
        $ticket->sla_resolution_status = 'paused';
        self::assertFalse(TicketPushNotification::relevant($ticket, 'sla_resolution_reminder', '2026-09-03T12:00:00Z'));
        $ticket->sla_resolution_status = 'overdue';
        self::assertFalse(TicketPushNotification::relevant($ticket, 'sla_resolution_reminder', '2026-09-03T12:00:00Z'));
        self::assertTrue(TicketPushNotification::relevant($ticket, 'sla_resolution_overdue', '2026-09-03T12:00:00Z'));
        $ticket->status = 'closed';
        self::assertFalse(TicketPushNotification::relevant($ticket, 'message'));
        $ticket->status = 'on_progress';
        self::assertFalse(TicketPushNotification::relevant($ticket, 'reopened'));
    }
}
