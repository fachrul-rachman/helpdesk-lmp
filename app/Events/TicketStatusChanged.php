<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data)
    {
    }

    public function broadcastOn(): array
    {
        $ticketId = (string) ($this->data['ticket_id'] ?? '');

        $channels = [
            new PrivateChannel("ticket.{$ticketId}"),
            new PrivateChannel('dashboard.spv'),
        ];

        $picId = (string) ($this->data['pic_id'] ?? '');
        if ($picId !== '') {
            $channels[] = new PrivateChannel("App.Models.User.{$picId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'TicketStatusChanged';
    }

    public function broadcastWith(): array
    {
        $data = $this->data;
        unset($data['pic_id']);

        return [
            'event' => 'TicketStatusChanged',
            'data' => $data,
        ];
    }
}

