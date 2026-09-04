<?php

namespace Tests\Unit;

use App\Jobs\SendTicketPushJob;
use App\Models\Ticket;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

// Model queries and HTTP transport are mocks; this never boots Laravel or a database.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SendTicketPushJobTest extends TestCase
{
    protected function setUp(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'webpush' => ['enabled' => true, 'public_key' => 'test-key', 'private_key' => 'test-secret', 'subject' => 'mailto:test@example.com', 'connection' => 'database'],
            'queue' => ['connections' => ['database' => ['driver' => 'database']]],
        ]));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Container::setInstance(null);
    }

    private function models(string $owner = 'pic-1'): object
    {
        $subscriptions = Mockery::mock('alias:App\Models\PushSubscription');
        $subscription = Mockery::mock();
        $subscription->id = 1;
        $subscription->refresh_token_id = 'session-1';
        $subscription->vapid_key_hash = hash('sha256', 'test-key');
        $subscription->endpoint = 'https://fcm.googleapis.com/fcm/send/test';
        $subscription->public_key = 'browser-key';
        $subscription->auth_token = 'browser-auth';
        $subscriptions->shouldReceive('query->where->find')->andReturn($subscription);

        $tickets = Mockery::mock('alias:App\Models\Ticket');
        $ticket = new Ticket;
        $ticket->id = 'ticket-1';
        $ticket->ticket_number = 'T26-001';
        $ticket->assigned_to = $owner;
        $ticket->status = 'open';
        $ticket->takeoverRequest = null;
        $tickets->shouldReceive('query->with->find')->andReturn($ticket);
        $users = Mockery::mock('alias:App\Models\User');
        $users->shouldReceive('query->where->whereIn->find')->andReturn((object) ['id' => 'pic-1', 'role' => 'pic']);

        return $subscription;
    }

    public function test_reassignment_prevents_delivery_to_previous_owner(): void
    {
        $this->models('pic-2');
        Mockery::mock('overload:Minishlink\WebPush\WebPush')->shouldNotReceive('sendOneNotification');
        (new SendTicketPushJob(1, 'ticket-1', 'pic-1', 'message', 'event-1', time() + 300))->handle();
        $this->addToAssertionCount(1);
    }

    public function test_revoked_session_removes_subscription_without_sending(): void
    {
        $subscription = $this->models();
        Mockery::mock('alias:App\Models\RefreshToken')->shouldReceive('query->where->whereNull->where->whereKey->exists')->andReturn(false);
        $subscription->shouldReceive('delete')->once();
        Mockery::mock('overload:Minishlink\WebPush\WebPush')->shouldNotReceive('sendOneNotification');
        (new SendTicketPushJob(1, 'ticket-1', 'pic-1', 'message', 'event-1', time() + 300))->handle();
        $this->addToAssertionCount(1);
    }

    public function test_expired_provider_endpoint_is_removed_and_payload_has_no_message_content(): void
    {
        $subscription = $this->models();
        Mockery::mock('alias:App\Models\RefreshToken')->shouldReceive('query->where->whereNull->where->whereKey->exists')->andReturn(true);
        $report = Mockery::mock();
        $report->shouldReceive('isSubscriptionExpired')->once()->andReturn(true);
        $subscription->shouldReceive('delete')->once();
        Mockery::mock('overload:Minishlink\WebPush\WebPush')->shouldReceive('sendOneNotification')->once()
            ->withArgs(function ($browser, $json): bool {
                $payload = json_decode($json, true);
                self::assertSame('pic-1', $payload['user_id']);
                self::assertSame('Pesan baru dari customer.', $payload['body']);
                self::assertArrayNotHasKey('content', $payload);

                return true;
            })->andReturn($report);
        (new SendTicketPushJob(1, 'ticket-1', 'pic-1', 'message', 'event-1', time() + 300))->handle();
    }
}
