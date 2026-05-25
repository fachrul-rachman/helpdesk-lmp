<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
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

        if ($ticketId !== '') {
            $assignedTo = (string) (Ticket::query()->where('id', $ticketId)->value('assigned_to') ?? '');
            if ($assignedTo !== '') {
                $channels[] = new PrivateChannel("App.Models.User.{$assignedTo}");
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'MessageSent';
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'MessageSent',
            'data' => $this->data,
        ];
    }
}
