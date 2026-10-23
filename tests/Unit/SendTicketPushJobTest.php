<?php

namespace Tests\Unit;

use App\Jobs\SendTicketPushJob;
use App\Models\Ticket;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private function validSession(): void
    {
        Mockery::mock('alias:App\Models\RefreshToken')->shouldReceive('query->where->whereNull->where->whereKey->exists')->andReturn(true);
    }

    private function report(?int $status, bool $success = false): object
    {
        $report = Mockery::mock();
        $report->shouldReceive('isSubscriptionExpired')->andReturn(in_array($status, [404, 410], true));
        $report->shouldReceive('getResponse')->andReturn($status === null ? null : new Response($status));
        $report->shouldReceive('isSuccess')->andReturn($success);

        return $report;
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

    public function test_successful_delivery_uses_vapid_ttl_and_a_bounded_non_redirecting_client(): void
    {
        $this->models();
        $this->validSession();
        $webPush = Mockery::mock('overload:Minishlink\WebPush\WebPush');
        $webPush->shouldReceive('__construct')->once()->withArgs(function ($auth, $options, $client): bool {
            self::assertSame(['subject' => 'mailto:test@example.com', 'publicKey' => 'test-key', 'privateKey' => 'test-secret'], $auth['VAPID']);
            self::assertGreaterThanOrEqual(1, $options['TTL']);
            self::assertLessThanOrEqual(300, $options['TTL']);
            self::assertInstanceOf(Client::class, $client);
            self::assertSame(15, $client->getConfig('timeout'));
            self::assertFalse($client->getConfig('allow_redirects'));

            return true;
        });
        $webPush->shouldReceive('sendOneNotification')->once()->andReturn($this->report(201, true));

        (new SendTicketPushJob(1, 'ticket-1', 'pic-1', 'message', 'event-1', time() + 300))->handle();
    }

    #[DataProvider('httpStatuses')]
    public function test_v11_sends_once_with_psr18_client_and_preserves_ttl(int $status): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response($status, $status === 302 ? ['Location' => 'https://untrusted.example/'] : [])]));
        $handler->push(Middleware::history($history));
        $client = new Client(['handler' => $handler, 'timeout' => 15, 'allow_redirects' => false]);
        $report = (new WebPush([], ['TTL' => 42], $client))->sendOneNotification(Subscription::create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test',
        ]));

        self::assertSame($status, $report->getResponse()?->getStatusCode());
        self::assertCount(1, $history);
        self::assertSame('42', $history[0]['request']->getHeaderLine('TTL'));
    }

    public static function httpStatuses(): array
    {
        return [[201], [302]];
    }

    public function test_redirect_is_not_treated_as_success(): void
    {
        $this->models();
        $this->validSession();
        Mockery::mock('overload:Minishlink\WebPush\WebPush')->shouldReceive('sendOneNotification')->once()->andReturn($this->report(302, true));

        $this->expectExceptionMessage('Web Push temporarily unavailable.');
        (new SendTicketPushJob(1, 'ticket-1', 'pic-1', 'message', 'event-1', time() + 300))->handle();
    }

    #[DataProvider('expiredStatuses')]
    public function test_expired_provider_status_removes_subscription(int $status): void
    {
        $subscription = $this->models();
        $this->validSession();
        $subscription->shouldReceive('delete')->once();
        Mockery::mock('overload:Minishlink\WebPush\WebPush')->shouldReceive('sendOneNotification')->once()->andReturn($this->report($status));

        (new SendTicketPushJob(1, 'ticket-1', 'pic-1', 'message', 'event-1', time() + 300))->handle();
        $this->addToAssertionCount(1);
    }

    public static function expiredStatuses(): array
    {
        return [[404], [410]];
    }

    #[DataProvider('retryStatuses')]
    public function test_retryable_provider_status_throws(int $status): void
    {
        $this->models();
        $this->validSession();
        Mockery::mock('overload:Minishlink\WebPush\WebPush')->shouldReceive('sendOneNotification')->once()->andReturn($this->report($status));

        $this->expectExceptionMessage('Web Push temporarily unavailable.');
        (new SendTicketPushJob(1, 'ticket-1', 'pic-1', 'message', 'event-1', time() + 300))->handle();
    }

    public static function retryStatuses(): array
    {
        return [[429], [500], [503]];
    }

    public function test_transport_failure_without_response_is_retried(): void
    {
        $this->models();
        $this->validSession();
        Mockery::mock('overload:Minishlink\WebPush\WebPush')->shouldReceive('sendOneNotification')->once()->andReturn($this->report(null));

        $this->expectExceptionMessage('Web Push temporarily unavailable.');
        (new SendTicketPushJob(1, 'ticket-1', 'pic-1', 'message', 'event-1', time() + 300))->handle();
    }
}
